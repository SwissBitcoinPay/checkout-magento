<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * What is under way for an order, each until a time by the database's clock (DatabaseClock): its
 * invoice being made, and its cancellation being carried out. One row per order (its table,
 * sattleme_payment_order), each mark set by one UPDATE whose condition reads the other mark in
 * that row: the database runs them one after the other, so that an invoice's making and a
 * cancellation never both start, whatever lock provider Magento uses, and with no lock at all.
 *
 * A mark lasts until its time at most: a request that died keeps no order from being cancelled,
 * nor from being paid, longer.
 */
class OrderMarks
{
    public const TABLE = 'sattleme_payment_order';

    /**
     * Seconds an invoice is said to be being made, at most: the SDK's call's limit
     * (Durations::CREATE_TIMEOUT), and what the call may overrun it by (a relay's answer read to
     * its end).
     */
    public const MAKING_SECONDS = Durations::CREATE_TIMEOUT + 10;

    /**
     * Seconds a cancellation is said to be carried out, at most: from Order::cancel() to the
     * order's save, which Magento's order cancellation by the customer makes once it sent its
     * e-mail.
     */
    public const CANCELING_SECONDS = 60;

    /**
     * @param ResourceConnection $resource The database.
     * @param DatabaseClock $clock The database's clock.
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DatabaseClock $clock
    ) {
    }

    /**
     * Marks order $orderId's invoice as being made: whether it was marked.
     *
     * Not when it is being made or cancelled already.
     *
     * @param int $orderId The order.
     * @return bool
     */
    public function startMaking(int $orderId): bool
    {
        $now = $this->clock->now();
        $this->ensureRow($orderId);

        return $this->connection()->update(
            $this->table(),
            ['making_until' => $now + self::MAKING_SECONDS],
            [
                'order_id = ?' => $orderId,
                '(making_until IS NULL OR making_until <= ?)' => $now,
                '(canceling_until IS NULL OR canceling_until <= ?)' => $now,
            ]
        ) === 1;
    }

    /**
     * Order $orderId's invoice is no longer being made.
     *
     * @param int $orderId The order.
     * @return void
     */
    public function stopMaking(int $orderId): void
    {
        $this->connection()->update($this->table(), ['making_until' => null], ['order_id = ?' => $orderId]);
    }

    /**
     * Whether order $orderId's invoice is being made now.
     *
     * @param int $orderId The order.
     * @return bool
     */
    public function isBeingMade(int $orderId): bool
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table(), new Expression('COUNT(*)'))
            ->where('order_id = ?', $orderId)
            ->where('making_until > ?', $this->clock->now());

        return (int) $connection->fetchOne($select) > 0;
    }

    /**
     * Marks order $orderId's cancellation as carried out: whether it is marked.
     *
     * Not when its invoice is being made. The mark is never cleared before its time, nor
     * shortened: an order whose cancellation another request marked until that time or later is
     * marked already.
     *
     * @param int $orderId The order.
     * @return bool
     */
    public function startCanceling(int $orderId): bool
    {
        $now = $this->clock->now();
        $until = $now + self::CANCELING_SECONDS;
        $this->ensureRow($orderId);
        $connection = $this->connection();
        $marked = $connection->update(
            $this->table(),
            ['canceling_until' => $until],
            [
                'order_id = ?' => $orderId,
                '(making_until IS NULL OR making_until <= ?)' => $now,
                '(canceling_until IS NULL OR canceling_until < ?)' => $until,
            ]
        );
        if ($marked === 1) {
            return true;
        }
        // No invoice is being made while a cancellation is marked (startMaking()): an order marked
        // until $until or later is marked; else its invoice is being made.
        $select = $connection->select()
            ->from($this->table(), new Expression('COUNT(*)'))
            ->where('order_id = ?', $orderId)
            ->where('canceling_until >= ?', $until);

        return (int) $connection->fetchOne($select) > 0;
    }

    /**
     * The order's row, made when it has none.
     *
     * @param int $orderId The order.
     * @return void
     */
    private function ensureRow(int $orderId): void
    {
        $this->connection()->insertOnDuplicate($this->table(), ['order_id' => $orderId], ['order_id']);
    }

    /**
     * The connection of the sales tables, which the table belongs to.
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection(AttemptRepository::CONNECTION);
    }

    /**
     * The table, with the installation's prefix.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE, AttemptRepository::CONNECTION);
    }
}
