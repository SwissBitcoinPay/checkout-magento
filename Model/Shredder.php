<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * What leads to the customer's coins on the blockchain, removed from an attempt: the deposit
 * address (which the payment URI holds too) and the transactions.
 */
class Shredder
{
    /**
     * What replaces a value removed.
     */
    public const SHREDDED = '█';

    /**
     * An invoice without its deposit address, which its payment URI (BIP-21) holds too.
     *
     * @param array<string,mixed> $invoice The invoice's JSON.
     * @return array<string,mixed>
     */
    public function invoice(array $invoice): array
    {
        $address = $invoice['onchainAddress'] ?? null;
        if (is_string($address) && $address !== '' && $address !== self::SHREDDED) {
            $invoice['onchainAddress'] = self::SHREDDED;
            $invoice['paymentUri'] = self::SHREDDED;
        }

        return $invoice;
    }

    /**
     * A status without the transactions that paid the invoice, nor the rest to pay.
     *
     * The rest's payment URI holds the address.
     *
     * @param array<string,mixed> $status The status's JSON.
     * @return array<string,mixed>
     */
    public function status(array $status): array
    {
        if (is_array($status['txids'] ?? null) && $status['txids'] !== []) {
            $status['txids'] = [];
        }
        if (is_array($status['rest'] ?? null)) {
            $status['rest'] = null;
        }

        return $status;
    }
}
