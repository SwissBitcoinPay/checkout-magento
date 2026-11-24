<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\Phrase;

/**
 * What the customer reads of a failure: never the SDK's internals.
 */
class CustomerMessages
{
    /**
     * The kind of failure an attempt's error code stands for, from the customer's side.
     *
     * @param string $code The attempt's error code.
     * @return string
     */
    public function kindOf(string $code): string
    {
        return match ($code) {
            'invalid_key', 'revoked', 'channel_not_found', 'invalid_invoice', 'checkout_unavailable',
            'environment', 'no_key' => PaymentFailure::UNAVAILABLE,
            'invalid_order', 'unsupported_currency', 'price' => PaymentFailure::ORDER,
            'deadline' => PaymentFailure::TOO_LATE,
            default => PaymentFailure::TRANSIENT,
        };
    }

    /**
     * What to tell the customer of a failure of $kind.
     *
     * @param string $kind The kind (PaymentFailure::*).
     * @return Phrase
     */
    public function message(string $kind): Phrase
    {
        return match ($kind) {
            PaymentFailure::UNAVAILABLE => __(
                'Bitcoin payments are not available right now. Please choose another payment method.'
            ),
            PaymentFailure::ORDER => __(
                'This order cannot be paid with Bitcoin. Please choose another payment method.'
            ),
            PaymentFailure::TOO_LATE => __(
                'The time to pay this order is almost over. Please choose another payment method.'
            ),
            PaymentFailure::NOT_PAYABLE => __('This order cannot be paid with Bitcoin now.'),
            default => __('We could not create your Bitcoin invoice right now. Please try again in a moment.'),
        };
    }
}
