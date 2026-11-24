<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * Why no invoice can be shown for an order now (CustomerMessages says it to the customer).
 */
class PaymentFailure extends \RuntimeException
{
    /**
     * The relays, the Lightning address or a rate source did not answer in time: try again.
     */
    public const TRANSIENT = 'transient';

    /**
     * The merchant must act (its key, its Lightning address, the server): another method.
     */
    public const UNAVAILABLE = 'unavailable';

    /**
     * The order's amount or currency: another method.
     */
    public const ORDER = 'order';

    /**
     * Too little time is left before Magento cancels the order: another method.
     */
    public const TOO_LATE = 'too_late';

    /**
     * The order cannot be paid with bitcoin now: paid, cancelled, or another method's.
     */
    public const NOT_PAYABLE = 'not_payable';

    /**
     * The kind of failure (one of the constants).
     *
     * @var string
     */
    private string $kind;

    /**
     * A failure of $kind.
     *
     * @param string $kind One of the constants.
     */
    public function __construct(string $kind)
    {
        parent::__construct($kind);
        $this->kind = $kind;
    }

    /**
     * The kind of failure (one of the constants).
     *
     * @return string
     */
    public function getKind(): string
    {
        return $this->kind;
    }
}
