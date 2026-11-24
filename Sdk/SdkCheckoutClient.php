<?php

declare(strict_types=1);

namespace Sattleme\Payment\Sdk;

use Sattleme\Payment\Vendor\Sattleme\Checkout\Cancellation;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Client;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * The SDK's Client, as the module calls it.
 */
class SdkCheckoutClient implements CheckoutClientInterface
{
    /**
     * Wraps a client.
     *
     * @param Client $client The SDK's client (it holds the key outside any property).
     */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @inheritDoc
     */
    public function createInvoice(array $order, Cancellation $cancellation): Invoice
    {
        return $this->client->createInvoice($order, $cancellation);
    }

    /**
     * @inheritDoc
     */
    public function getStatus(array $invoice, Cancellation $cancellation): PaymentStatus
    {
        return $this->client->getStatus($invoice, $cancellation);
    }

    /**
     * @inheritDoc
     */
    public function merchant(Cancellation $cancellation): Merchant
    {
        return $this->client->merchant($cancellation);
    }
}
