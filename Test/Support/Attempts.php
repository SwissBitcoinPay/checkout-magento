<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Support;

use Sattleme\Payment\Model\Attempt;

/**
 * Attempts as the repository reads them, for the tests.
 *
 * @phpstan-type Fields array{
 *     id?: int, orderId?: int, storeId?: int, ref?: string, state?: string,
 *     invoice?: array<string,mixed>|null, status?: array<string,mixed>|null, expiresAt?: int|null,
 *     isFinal?: bool, outcome?: string, error?: string, errors?: int, createdAt?: int,
 *     checkedAt?: int|null, checks?: int, nextCheckAt?: int|null, leaseUntil?: int|null, shredded?: bool
 * }
 */
class Attempts
{
    /**
     * An attempt with an invoice ending at $expiresAt, its last status $status.
     *
     * @param int $expiresAt The invoice's end.
     * @param array<string,mixed>|null $status The last status's JSON.
     * @param Fields $fields Other fields (the constructor's names).
     * @return Attempt
     */
    public static function withInvoice(int $expiresAt, ?array $status = null, array $fields = []): Attempt
    {
        $order = ['ref' => 'm2-000000001-' . str_repeat('a', 32), 'fiat' => ['amount' => '24.5', 'currency' => 'CHF']];
        $invoice = Sdk::invoice($order, $expiresAt - 900)->toArray();

        return self::make(
            $fields
            + ['state' => Attempt::STATE_INVOICE, 'invoice' => $invoice, 'status' => $status, 'expiresAt' => $expiresAt]
        );
    }

    /**
     * An attempt of the given fields (the constructor's names), the others an attempt being made.
     *
     * @param Fields $fields The fields.
     * @return Attempt
     */
    public static function make(array $fields = []): Attempt
    {
        return new Attempt(
            $fields['id'] ?? 1,
            $fields['orderId'] ?? 1,
            $fields['storeId'] ?? 1,
            $fields['ref'] ?? 'm2-000000001-' . str_repeat('a', 32),
            $fields['state'] ?? Attempt::STATE_CREATING,
            $fields['invoice'] ?? null,
            $fields['status'] ?? null,
            $fields['expiresAt'] ?? null,
            $fields['isFinal'] ?? false,
            $fields['outcome'] ?? '',
            $fields['error'] ?? '',
            $fields['errors'] ?? 0,
            $fields['createdAt'] ?? 1_800_000_000,
            $fields['checkedAt'] ?? null,
            $fields['checks'] ?? 0,
            $fields['nextCheckAt'] ?? null,
            $fields['leaseUntil'] ?? null,
            $fields['shredded'] ?? false
        );
    }
}
