<?php

declare(strict_types=1);

namespace Sattleme\Payment\Sdk;

use Sattleme\Payment\Vendor\Sattleme\Checkout\Cancellation;
use Sattleme\Payment\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * What the module asks the sattle.me SDK (sattleme/checkout), for one integration key.
 *
 * The three calls it makes. In production, SdkCheckoutClient: the SDK's Client. The tests fake
 * this interface, and only this one: every value and exception they give is the SDK's own.
 *
 * @api
 */
interface CheckoutClientInterface
{
    /**
     * An invoice for an order (the SDK's createInvoice).
     *
     * @param array<string,mixed> $order Its reference, price, duration and note.
     * @param Cancellation $cancellation When to give up.
     * @return Invoice
     * @throws SattlemeException The SDK's errors, InvoicePendingException and OrderPaidException included.
     * @throws CancelledException Once $cancellation reads cancelled.
     */
    public function createInvoice(array $order, Cancellation $cancellation): Invoice;

    /**
     * The payment status of an invoice, from its stored JSON (the SDK's getStatus).
     *
     * @param array<string,mixed> $invoice The invoice's JSON, decoded.
     * @param Cancellation $cancellation When to give up.
     * @return PaymentStatus
     * @throws SattlemeException The SDK's errors.
     * @throws CancelledException Once $cancellation reads cancelled.
     */
    public function getStatus(array $invoice, Cancellation $cancellation): PaymentStatus;

    /**
     * The merchant as the integration sees it (the SDK's merchant()): what checks a key.
     *
     * @param Cancellation $cancellation When to give up.
     * @return Merchant
     * @throws SattlemeException The SDK's errors (revoked: the merchant removed the integration).
     * @throws CancelledException Once $cancellation reads cancelled.
     */
    public function merchant(Cancellation $cancellation): Merchant;
}
