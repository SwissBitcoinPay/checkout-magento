<?php

declare(strict_types=1);

namespace Sattleme\PaymentE2e\Model;

use Sattleme\Payment\Sdk\CheckoutClientInterface;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Cancellation;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Fiat;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\Payment\Vendor\Sattleme\Checkout\RateInfo;

/**
 * The SDK faked for the end-to-end test, its state in files: each invoice it made, the reference
 * of the last one (last-ref), and the invoices the test marked paid (paid-<ref>). A bitcoin is
 * worth 50 000 in every currency.
 */
class FakeClient implements CheckoutClientInterface
{
    /**
     * @param string $dir Where its state is.
     */
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @inheritDoc
     */
    public function createInvoice(array $order, Cancellation $cancellation): Invoice
    {
        $ref = is_string($order['ref'] ?? null) ? $order['ref'] : 'e2e';
        $fiat = is_array($order['fiat'] ?? null) ? $order['fiat'] : [];
        $amount = is_string($fiat['amount'] ?? null) && is_numeric($fiat['amount']) ? $fiat['amount'] : '0';
        $currency = is_string($fiat['currency'] ?? null) ? $fiat['currency'] : 'USD';
        $duration = is_int($order['durationSeconds'] ?? null) ? $order['durationSeconds'] : 900;
        $now = time();
        $sats = bcadd(bcdiv(bcmul($amount, '100000000', 8), '50000', 8), '0.99999999', 0);
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $minor = ltrim($whole . $fraction, '0');
        $bolt11 = 'lnbc' . $sats . 'n1e2e' . substr(hash('sha256', $ref), 0, 32);
        $address = 'bc1p5d7rjq7g6rdk2yhzks9smlaqtedr4dekq08ge8ztwac72sfr9rusxg3297';
        $btc = rtrim(rtrim(bcdiv($sats, '100000000', 8), '0'), '.');
        $invoice = new Invoice(
            $ref,
            $bolt11,
            hash('sha256', $bolt11),
            'https://e2e.invalid/verify',
            $address,
            'bitcoin:' . $address . '?amount=' . $btc . '&lightning=' . $bolt11,
            $sats,
            new Fiat($whole . '.' . $fraction, $currency, is_numeric($minor) ? $minor : '0', 2),
            new RateInfo('e2e', $currency, '50000', $now, $now),
            is_string($order['note'] ?? null) ? $order['note'] : '',
            $now,
            $now + $duration,
            null,
            null
        );
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0770, true);
        }
        file_put_contents($this->dir . '/last-ref', $ref);

        return $invoice;
    }

    /**
     * @inheritDoc
     */
    public function getStatus(array $invoice, Cancellation $cancellation): PaymentStatus
    {
        $ref = is_string($invoice['ref'] ?? null) ? $invoice['ref'] : '';
        if ($ref !== '' && is_file($this->dir . '/paid-' . $ref)) {
            return PaymentStatus::paidByLightning(hash('sha256', 'preimage ' . $ref));
        }
        $expiresAt = is_int($invoice['expiresAt'] ?? null) ? $invoice['expiresAt'] : time();

        return PaymentStatus::open($expiresAt, '0', null);
    }

    /**
     * @inheritDoc
     */
    public function merchant(Cancellation $cancellation): Merchant
    {
        return new Merchant('E2E Shop', '1', '1000000', 3);
    }
}
