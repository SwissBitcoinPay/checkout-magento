<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * The attempts' table (sattleme_payment_attempt): Magento's orders are only ever read and written
 * through Magento's own API, never here.
 *
 * Checks of one attempt never run at once: a check claims it first (a lease in its row, until a
 * time that is the lease's identity), and writes its status only while it holds it.
 *
 */
class AttemptRepository
{
    public const TABLE = 'sattleme_payment_attempt';

    /**
     * The connection of the sales tables, which the attempts' table belongs to.
     */
    public const CONNECTION = 'sales';

    /**
     * Reads and writes the attempts' table.
     *
     * @param ResourceConnection $resource The database.
     * @param Shredder $shredder What removes the customer's personal data.
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Shredder $shredder
    ) {
    }

    /**
     * Stores a new attempt to pay $orderId with reference $ref: its id.
     *
     * @param int $orderId The order.
     * @param int $storeId The order's store.
     * @param string $ref The attempt's reference.
     * @param int $now The time, in Unix seconds.
     * @return int
     */
    public function insertAttempt(int $orderId, int $storeId, string $ref, int $now): int
    {
        $connection = $this->connection();
        $connection->insert(
            $this->table(),
            [
                'order_id' => $orderId,
                'store_id' => $storeId,
                'ref' => $ref,
                'state' => Attempt::STATE_CREATING,
                'created_at' => $now,
            ]
        );

        $id = $connection->fetchOne($connection->select()->from($this->table(), 'attempt_id')->where('ref = ?', $ref));

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Attempt $id, as stored now.
     *
     * @param int $id The attempt.
     * @return Attempt|null
     */
    public function find(int $id): ?Attempt
    {
        $connection = $this->connection();
        $row = $connection->fetchRow($connection->select()->from($this->table())->where('attempt_id = ?', $id));

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * The attempt of reference $ref.
     *
     * @param string $ref The reference.
     * @return Attempt|null
     */
    public function findByRef(string $ref): ?Attempt
    {
        $connection = $this->connection();
        $row = $connection->fetchRow($connection->select()->from($this->table())->where('ref = ?', $ref));

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * The attempts of $orderId, the latest first.
     *
     * @param int $orderId The order.
     * @param int $limit How many at most.
     * @return list<Attempt>
     */
    public function forOrder(int $orderId, int $limit = 100): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where('order_id = ?', $orderId)
            ->order('attempt_id DESC')
            ->limit($limit);

        return $this->attempts($connection->fetchAll($select));
    }

    /**
     * The latest attempt of $orderId.
     *
     * @param int $orderId The order.
     * @return Attempt|null
     */
    public function latestForOrder(int $orderId): ?Attempt
    {
        return $this->forOrder($orderId, 1)[0] ?? null;
    }

    /**
     * The latest attempt of $orderId whose invoice may still be paid or confirmed (Attempt::isLive).
     *
     * @param int $orderId The order.
     * @param int $now The time, in Unix seconds.
     * @return Attempt|null
     */
    public function liveForOrder(int $orderId, int $now): ?Attempt
    {
        foreach ($this->forOrder($orderId, 5) as $attempt) {
            if ($attempt->isLive($now)) {
                return $attempt;
            }
        }

        return null;
    }

    /**
     * Whether bitcoin came for $orderId, by any of its attempts (Attempt::hasCome).
     *
     * @param int $orderId The order.
     * @return bool
     */
    public function hasComeForOrder(int $orderId): bool
    {
        foreach ($this->forOrder($orderId) as $attempt) {
            if ($attempt->hasCome()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The attempt failed with $code, and an invoice may exist for its reference.
     *
     * A network failure, a call that ran out of time: the next try asks the SDK again with it.
     *
     * @param int $id The attempt.
     * @param string $code The error's code.
     * @return void
     */
    public function markRetry(int $id, string $code): void
    {
        $this->connection()->update(
            $this->table(),
            ['state' => Attempt::STATE_RETRY, 'error' => substr($code, 0, 64)],
            ['attempt_id = ?' => $id, 'is_final = ?' => 0]
        );
    }

    /**
     * The attempt failed with $code, for good: it never gave an invoice that was shown.
     *
     * @param int $id The attempt.
     * @param string $code The error's code.
     * @return void
     */
    public function markFailed(int $id, string $code): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'state' => Attempt::STATE_FAILED,
                'error' => substr($code, 0, 64),
                'is_final' => 1,
                'next_check_at' => null,
            ],
            ['attempt_id = ?' => $id, 'is_final = ?' => 0]
        );
    }

    /**
     * Stores the invoice the SDK made for attempt $id (its JSON), and when it is checked first.
     *
     * @param int $id The attempt.
     * @param array<string,mixed> $invoice The invoice's JSON (Invoice::toArray(), or DeclaredInvoice's).
     * @param int $expiresAt When the invoice ends (Unix seconds).
     * @param int $nextCheckAt When it is checked next (Unix seconds).
     * @return void
     */
    public function storeInvoice(int $id, array $invoice, int $expiresAt, int $nextCheckAt): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'state' => Attempt::STATE_INVOICE,
                'invoice' => $this->json($invoice),
                'expires_at' => $expiresAt,
                'error' => '',
                'next_check_at' => $nextCheckAt,
            ],
            ['attempt_id = ?' => $id, 'is_final = ?' => 0]
        );
    }

    /**
     * Claims the check of attempt $id until $leaseUntil: false when it is final, when another
     * check holds it, or when one ended less than $minInterval seconds ago. Atomic: of two
     * requests that claim it at once, one gets it.
     *
     * @param int $id The attempt.
     * @param int $now The time, in Unix seconds.
     * @param int $leaseUntil The check's lease, until when it holds the attempt (Unix seconds).
     * @param int $minInterval Seconds since the last check, at least.
     * @return bool
     */
    public function claim(int $id, int $now, int $leaseUntil, int $minInterval): bool
    {
        return $this->connection()->update(
            $this->table(),
            ['lease_until' => $leaseUntil],
            [
                'attempt_id = ?' => $id,
                'state = ?' => Attempt::STATE_INVOICE,
                'is_final = ?' => 0,
                '(lease_until IS NULL OR lease_until < ?)' => $now,
                '(checked_at IS NULL OR checked_at <= ?)' => $now - $minInterval,
            ]
        ) === 1;
    }

    /**
     * Stores the status a check found, while that check still holds the attempt ($leaseUntil).
     *
     * False when it no longer does (it outlived its lease, or the attempt is final).
     *
     * @param int $id The attempt.
     * @param int $leaseUntil The check's lease.
     * @param array<string,mixed> $status The status's JSON (PaymentStatus::toArray()).
     * @param int $now The time, in Unix seconds.
     * @return bool
     */
    public function storeStatus(int $id, int $leaseUntil, array $status, int $now): bool
    {
        return $this->connection()->update(
            $this->table(),
            [
                'status' => $this->json($status),
                'checked_at' => $now,
                'checks' => new Expression('checks + 1'),
                'error' => '',
            ],
            ['attempt_id = ?' => $id, 'lease_until = ?' => $leaseUntil, 'is_final = ?' => 0]
        ) === 1;
    }

    /**
     * A check holding the attempt failed with $code: one more failure in a row, the lease freed.
     *
     * @param int $id The attempt.
     * @param int $leaseUntil The check's lease.
     * @param string $code The error's code.
     * @param int $now The time, in Unix seconds.
     * @return void
     */
    public function recordFailure(int $id, int $leaseUntil, string $code, int $now): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'error' => substr($code, 0, 64),
                'errors' => new Expression('errors + 1'),
                'checked_at' => $now,
                'lease_until' => null,
            ],
            ['attempt_id = ?' => $id, 'lease_until = ?' => $leaseUntil]
        );
    }

    /**
     * Frees the lease of a check that applied its status.
     *
     * Its failures in a row are over, and the attempt is final when $outcome is given (paid,
     * expired). Never makes it non-final.
     *
     * @param int $id The attempt.
     * @param int $leaseUntil The check's lease.
     * @param string|null $outcome How it ended.
     * @return void
     */
    public function release(int $id, int $leaseUntil, ?string $outcome): void
    {
        $data = ['lease_until' => null, 'errors' => 0];
        if ($outcome !== null) {
            $data += ['is_final' => 1, 'outcome' => $outcome, 'next_check_at' => null];
        }
        $this->connection()->update($this->table(), $data, ['attempt_id = ?' => $id, 'lease_until = ?' => $leaseUntil]);
    }

    /**
     * Makes attempt $id final with $outcome, unless it is final already: whether this call did.
     *
     * @param int $id The attempt.
     * @param string $outcome How it ended (paid, expired, abandoned).
     * @param string $error The error's code.
     * @return bool
     */
    public function markFinal(int $id, string $outcome, string $error = ''): bool
    {
        return $this->connection()->update(
            $this->table(),
            [
                'is_final' => 1,
                'outcome' => $outcome,
                'error' => substr($error, 0, 64),
                'next_check_at' => null,
                'lease_until' => null,
            ],
            ['attempt_id = ?' => $id, 'is_final = ?' => 0]
        ) === 1;
    }

    /**
     * When attempt $id is checked next in the background (unless it is final).
     *
     * @param int $id The attempt.
     * @param int $nextCheckAt When (Unix seconds).
     * @return void
     */
    public function scheduleNext(int $id, int $nextCheckAt): void
    {
        $this->connection()->update(
            $this->table(),
            ['next_check_at' => $nextCheckAt],
            ['attempt_id = ?' => $id, 'is_final = ?' => 0]
        );
    }

    /**
     * Attempts with an invoice, not final, whose next background check is due by $now.
     *
     * The longest due first, $limit at most.
     *
     * @param int $now The time, in Unix seconds.
     * @param int $limit How many at most.
     * @return list<Attempt>
     */
    public function due(int $now, int $limit): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where('is_final = ?', 0)
            ->where('state = ?', Attempt::STATE_INVOICE)
            ->where('next_check_at IS NOT NULL')
            ->where('next_check_at <= ?', $now)
            ->order('next_check_at ASC')
            ->limit($limit);

        return $this->attempts($connection->fetchAll($select));
    }

    /**
     * Attempts with an invoice, not final, made before $before: to be given up on. $limit at most.
     *
     * @param int $before The moment, in Unix seconds.
     * @param int $limit How many at most.
     * @return list<Attempt>
     */
    public function olderThan(int $before, int $limit): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where('is_final = ?', 0)
            ->where('state = ?', Attempt::STATE_INVOICE)
            ->where('created_at < ?', $before)
            ->order('created_at ASC')
            ->limit($limit);

        return $this->attempts($connection->fetchAll($select));
    }

    /**
     * Marks the attempts of $orderId as shredded (the customer's personal data removed): those
     * final lose what leads to the customer's coins now, the others once final. How many lost it
     * now.
     *
     * @param int $orderId The order.
     * @return int
     */
    public function shredOrder(int $orderId): int
    {
        $this->connection()->update($this->table(), ['shredded' => 1], ['order_id = ?' => $orderId]);
        $count = 0;
        foreach ($this->forOrder($orderId) as $attempt) {
            if ($this->shredIfFinal($attempt->id)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Removes the deposit address and the transactions of attempt $id, when final and shredded.
     *
     * The payment URIs that hold the address too. Whether it changed anything.
     *
     * @param int $id The attempt.
     * @return bool
     */
    public function shredIfFinal(int $id): bool
    {
        $attempt = $this->find($id);
        if ($attempt === null || !$attempt->shredded || !$attempt->isFinal) {
            return false;
        }
        $invoice = $attempt->invoice === null ? null : $this->shredder->invoice($attempt->invoice);
        $status = $attempt->status === null ? null : $this->shredder->status($attempt->status);
        if ($invoice === $attempt->invoice && $status === $attempt->status) {
            return false;
        }
        $this->connection()->update(
            $this->table(),
            [
                'invoice' => $invoice === null ? null : $this->json($invoice),
                'status' => $status === null ? null : $this->json($status),
            ],
            ['attempt_id = ?' => $id]
        );

        return true;
    }

    /**
     * The connection of the attempts' table.
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection(self::CONNECTION);
    }

    /**
     * The attempts' table, with the installation's prefix.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE, self::CONNECTION);
    }

    /**
     * Rows as attempts.
     *
     * @param array<mixed> $rows The rows.
     * @return list<Attempt>
     */
    private function attempts(array $rows): array
    {
        $attempts = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $attempts[] = $this->hydrate($row);
            }
        }

        return $attempts;
    }

    /**
     * A row as an attempt.
     *
     * @param array<mixed> $row The row, by column.
     * @return Attempt
     */
    private function hydrate(array $row): Attempt
    {
        return new Attempt(
            $this->intOf($row['attempt_id'] ?? 0),
            $this->intOf($row['order_id'] ?? 0),
            $this->intOf($row['store_id'] ?? 0),
            $this->stringOf($row['ref'] ?? ''),
            $this->stringOf($row['state'] ?? ''),
            $this->jsonOf($row['invoice'] ?? null),
            $this->jsonOf($row['status'] ?? null),
            $this->nullableIntOf($row['expires_at'] ?? null),
            $this->intOf($row['is_final'] ?? 0) === 1,
            $this->stringOf($row['outcome'] ?? ''),
            $this->stringOf($row['error'] ?? ''),
            $this->intOf($row['errors'] ?? 0),
            $this->intOf($row['created_at'] ?? 0),
            $this->nullableIntOf($row['checked_at'] ?? null),
            $this->intOf($row['checks'] ?? 0),
            $this->nullableIntOf($row['next_check_at'] ?? null),
            $this->nullableIntOf($row['lease_until'] ?? null),
            $this->intOf($row['shredded'] ?? 0) === 1
        );
    }

    /**
     * JSON text as an array, else null.
     *
     * @param mixed $value The column's value.
     * @return array<string,mixed>|null
     */
    private function jsonOf(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }
        $result = [];
        foreach ($decoded as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * A column as an int.
     *
     * @param mixed $value The column's value.
     * @return int
     */
    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A nullable column as an int, or null.
     *
     * @param mixed $value The column's value.
     * @return int|null
     */
    private function nullableIntOf(mixed $value): ?int
    {
        return $value === null ? null : $this->intOf($value);
    }

    /**
     * A column as a string.
     *
     * @param mixed $value The column's value.
     * @return string
     */
    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * $data as JSON, as the SDK writes it: unescaped slashes and Unicode.
     *
     * @param array<string,mixed> $data The data.
     * @return string
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
