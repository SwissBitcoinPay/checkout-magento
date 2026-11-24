<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Sales\Model\Order;

/**
 * Where an order's bitcoin payment is, as its payment page shows it: from what the module stored
 * (the attempts, their invoices and last statuses) and the order's state, never from the browser.
 */
class PagePhase
{
    /**
     * No attempt yet: a button makes the invoice.
     */
    public const START = 'start';

    /**
     * The invoice can be paid: its QR code, its time left.
     */
    public const PAYABLE = 'payable';

    /**
     * Its status is being found: an invoice handed out again before a check found it open, one
     * being made, or one whose time is over (a check after its end tells whether it was paid).
     */
    public const CHECKING = 'checking';

    /**
     * Enough came on-chain in time, being confirmed (3 confirmations).
     */
    public const CONFIRMING = 'confirming';

    public const PAID = 'paid';

    /**
     * Ended unpaid: the order is cancelled, the customer may order again.
     */
    public const EXPIRED = 'expired';

    /**
     * Ended underpaid: the shop settles it with the customer.
     */
    public const UNDERPAID = 'underpaid';

    /**
     * No final status after 90 days.
     */
    public const ABANDONED = 'abandoned';

    /**
     * No invoice could be made: the reason, a button to try again or to choose another method.
     */
    public const FAILED = 'failed';

    /**
     * The order cannot be paid with bitcoin (cancelled, on hold, paid otherwise).
     */
    public const CLOSED = 'closed';

    /**
     * The phase of $order's payment.
     *
     * @param string $orderState The order's state (Order::STATE_*).
     * @param Attempt|null $latest Its latest attempt.
     * @param Attempt|null $live Its live attempt (Attempt::isLive).
     * @param int $now The time, in Unix seconds.
     * @return string
     */
    public function of(string $orderState, ?Attempt $latest, ?Attempt $live, int $now): string
    {
        if ($live !== null) {
            if (!$live->hasInvoice()) {
                return self::CHECKING;
            }

            return match (true) {
                $live->statusState() === 'paid' => self::PAID,
                $live->statusState() === 'unconfirmed' => self::CONFIRMING,
                $live->isDeclared() && $live->statusState() === null => self::CHECKING,
                $live->isPayable($now) && !$this->mayShowInvoice($orderState) => self::CLOSED,
                $live->isPayable($now) => self::PAYABLE,
                default => self::CHECKING,
            };
        }
        $pending = $orderState === Order::STATE_PENDING_PAYMENT;
        if ($latest === null) {
            return $pending ? self::START : self::CLOSED;
        }

        return match (true) {
            $latest->outcome === Attempt::OUTCOME_PAID => self::PAID,
            $latest->outcome === Attempt::OUTCOME_EXPIRED => $latest->hasReceived() ? self::UNDERPAID : self::EXPIRED,
            $latest->outcome === Attempt::OUTCOME_ABANDONED => self::ABANDONED,
            $pending => self::FAILED,
            default => self::CLOSED,
        };
    }

    /**
     * Whether an order in state $orderState may have its invoice shown, to be paid.
     *
     * While it waits for its payment, or is on hold (the merchant may hold an order whose invoice
     * is being paid); never once cancelled, completed or closed, nor once paid otherwise.
     *
     * @param string $orderState The order's state (Order::STATE_*).
     * @return bool
     */
    public function mayShowInvoice(string $orderState): bool
    {
        return in_array($orderState, [Order::STATE_PENDING_PAYMENT, Order::STATE_HOLDED], true);
    }

    /**
     * Whether, in $phase, the order may still get an invoice of this attempt or a new one.
     *
     * The page's button "Try again".
     *
     * @param string $phase The phase.
     * @return bool
     */
    public function canCreate(string $phase): bool
    {
        return $phase === self::START || $phase === self::FAILED;
    }

    /**
     * Whether, in $phase, the customer may leave this method for another one.
     *
     * The order is cancelled, its cart given back: only when no invoice of it may be paid.
     *
     * @param string $phase The phase.
     * @return bool
     */
    public function canLeave(string $phase): bool
    {
        return $phase === self::START || $phase === self::FAILED;
    }

    /**
     * Whether, in $phase, the page checks the payment (the status endpoint, the button "Check").
     *
     * @param string $phase The phase.
     * @return bool
     */
    public function isChecked(string $phase): bool
    {
        return in_array($phase, [self::PAYABLE, self::CHECKING, self::CONFIRMING], true);
    }
}
