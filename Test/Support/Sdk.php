<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Support;

use Sattleme\Payment\Vendor\Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Fiat;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\Payment\Vendor\Sattleme\Checkout\RateInfo;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Rest;

/**
 * The SDK's own values, as the fakes give them: invoices, statuses, a merchant.
 */
class Sdk
{
    /**
     * The tests' price of a bitcoin, in any currency.
     */
    public const PRICE = '50000';

    /**
     * A taproot address (BIP-350's test vector).
     */
    public const ADDRESS = 'bc1p5d7rjq7g6rdk2yhzks9smlaqtedr4dekq08ge8ztwac72sfr9rusxg3297';

    /**
     * A transaction id.
     */
    public const TXID = '6f7cf9580f1c2dfb3c4d5d043cdbb128c640e3f20161245aa7372e9666168516';

    /**
     * A Lightning payment's preimage.
     */
    public const PREIMAGE = '0b7ee2c3f2e1d9b0a4c3d2e1f0a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4e3f2a1';

    /**
     * A BOLT11 invoice (its text only matters to the tests).
     */
    public const BOLT11 = 'lnbc490u1pjtestinvoicexyz';

    /**
     * A key of the right form (checkout-spec's vector: relay.example, never reached).
     */
    public const KEY = 'sattleme_ck_qEVejHuCZpF4XMmBSw5p7iZYxCtm20XxzT1fnJ9VB8o'
        . '.uPTAz1d8bbQaw2Jx22Wwrga2vI2F8allY5NrPC3Aogk'
        . '.cmVsYXkuZXhhbXBsZQ';

    /**
     * The invoice createInvoice makes of $order (its parameters), at $now.
     *
     * @param array<mixed> $order The order's parameters, as the module passed them.
     * @param int $now When it is made.
     * @param bool $onchain Whether it has a deposit address.
     * @param string|null $bolt11 Its Lightning invoice (null: on-chain only).
     * @return Invoice
     */
    public static function invoice(
        array $order,
        int $now,
        bool $onchain = true,
        ?string $bolt11 = self::BOLT11
    ): Invoice {
        $fiat = is_array($order['fiat'] ?? null) ? $order['fiat'] : [];
        $amount = is_string($fiat['amount'] ?? null) && is_numeric($fiat['amount']) ? $fiat['amount'] : '24.5';
        $currency = is_string($fiat['currency'] ?? null) ? $fiat['currency'] : 'USD';
        $duration = is_int($order['durationSeconds'] ?? null) ? $order['durationSeconds'] : 900;
        $sats = self::sats($amount);
        $address = $onchain ? self::ADDRESS : null;
        $uri = $address !== null
            ? 'bitcoin:' . $address . '?amount=' . self::btc($sats) . ($bolt11 !== null ? '&lightning=' . $bolt11 : '')
            : 'lightning:' . (string) $bolt11;

        return new Invoice(
            is_string($order['ref'] ?? null) ? $order['ref'] : 'm2-1-' . str_repeat('a', 32),
            $bolt11,
            $bolt11 !== null ? str_repeat('b', 64) : null,
            $bolt11 !== null ? 'https://breez.tips/lnurlp/verify/1' : null,
            $address,
            $uri,
            $sats,
            self::fiat($amount, $currency),
            new RateInfo('coingecko', $currency, self::PRICE, $now, $now),
            is_string($order['note'] ?? null) ? $order['note'] : 'Order',
            $now,
            $now + $duration,
            null,
            null
        );
    }

    /**
     * $invoice as the SDK hands it out again (InvoicePending, OrderPaid), declared at $at.
     *
     * @param Invoice $invoice The invoice.
     * @param int $at When it was declared.
     * @return DeclaredInvoice
     */
    public static function declared(Invoice $invoice, int $at): DeclaredInvoice
    {
        return new DeclaredInvoice(
            $invoice->ref,
            $invoice->bolt11,
            $invoice->paymentHash,
            $invoice->verifyUrl,
            $invoice->onchainAddress,
            $invoice->paymentUri,
            $invoice->sats,
            $invoice->fiat,
            $invoice->note,
            $invoice->createdAt,
            $invoice->expiresAt,
            $at
        );
    }

    /**
     * $amount in $currency (two decimals) as the SDK's Fiat.
     *
     * @param numeric-string $amount The amount ("24.5").
     * @param string $currency The currency.
     * @return Fiat
     */
    public static function fiat(string $amount, string $currency): Fiat
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        $minor = ltrim($whole . $fraction, '0');

        return new Fiat($whole . '.' . $fraction, $currency, is_numeric($minor) ? $minor : '0', 2);
    }

    /**
     * $amount (a decimal) in sats at PRICE, rounded up.
     *
     * @param numeric-string $amount The amount.
     * @return numeric-string
     */
    public static function sats(string $amount): string
    {
        return bcadd(bcdiv(bcmul($amount, '100000000', 8), self::PRICE, 8), '0.99999999', 0);
    }

    /**
     * $sats in bitcoin.
     *
     * @param numeric-string $sats The sats.
     * @return string
     */
    public static function btc(string $sats): string
    {
        return rtrim(rtrim(bcdiv($sats, '100000000', 8), '0'), '.');
    }

    /**
     * Open, until $expiresAt; $received with a rest of $rest sats when given.
     *
     * @param int $expiresAt The invoice's end.
     * @param numeric-string $received What came.
     * @param numeric-string|null $rest What is left.
     * @return PaymentStatus
     */
    public static function open(int $expiresAt, string $received = '0', ?string $rest = null): PaymentStatus
    {
        return PaymentStatus::open(
            $expiresAt,
            $received,
            $rest === null ? null : new Rest(
                $rest,
                'lnbc1resttestinvoice',
                'bitcoin:' . self::ADDRESS . '?amount=' . self::btc($rest) . '&lightning=lnbc1resttestinvoice'
            )
        );
    }

    /**
     * Being confirmed.
     *
     * @param numeric-string $received What came.
     * @param int $confirmations Its confirmations.
     * @return PaymentStatus
     */
    public static function unconfirmed(string $received, int $confirmations = 1): PaymentStatus
    {
        return PaymentStatus::unconfirmed($received, $confirmations, [self::TXID]);
    }

    /**
     * Paid in Lightning.
     *
     * @return PaymentStatus
     */
    public static function paidLightning(): PaymentStatus
    {
        return PaymentStatus::paidByLightning(self::PREIMAGE);
    }

    /**
     * Paid on-chain, $received.
     *
     * @param numeric-string $received What came.
     * @return PaymentStatus
     */
    public static function paidOnchain(string $received): PaymentStatus
    {
        return PaymentStatus::paidOnchain($received, [self::TXID]);
    }

    /**
     * Paid on-chain and by invoices of the rest, $received in all.
     *
     * @param numeric-string $received What came.
     * @return PaymentStatus
     */
    public static function paidMixed(string $received): PaymentStatus
    {
        return PaymentStatus::paidMixed($received, [self::TXID], [self::PREIMAGE]);
    }

    /**
     * Expired, $received.
     *
     * @param numeric-string $received What came.
     * @return PaymentStatus
     */
    public static function expired(string $received = '0'): PaymentStatus
    {
        return PaymentStatus::expired($received);
    }

    /**
     * The merchant of the tests.
     *
     * @return Merchant
     */
    public static function merchant(): Merchant
    {
        return new Merchant('Test Coffee', '1', '1000000', 12);
    }
}
