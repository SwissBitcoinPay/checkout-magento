<?php

declare(strict_types=1);

namespace Sattleme\Payment\Sdk;

use Magento\Framework\Phrase;
use Sattleme\Payment\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

/**
 * A short stable code of each failure (stored, logged), a description for the log (the SDK's
 * messages never hold a secret; nothing else of an unexpected exception is written), and the
 * merchant's words for the codes.
 */
class Errors
{
    /**
     * A call that ran out of time (its Cancellation).
     */
    public const TIMEOUT = 'timeout';

    /**
     * PHP lacks what the SDK needs.
     */
    public const ENVIRONMENT = 'environment';

    /**
     * Magento could not take a status a check found (a database error, another module).
     */
    public const APPLY = 'apply';

    /**
     * The invoice made would end after the order's deadline: never shown.
     */
    public const DEADLINE = 'deadline';

    /**
     * The invoice the SDK gave is not of the order's price: never shown.
     */
    public const MISMATCH = 'mismatch';

    /**
     * Another request held the order's lock.
     */
    public const LOCK = 'lock';

    /**
     * No integration key is set.
     */
    public const NO_KEY = 'no_key';

    /**
     * The order's total, in its currency, is not a price the SDK takes.
     */
    public const PRICE = 'price';

    /**
     * No final status 90 days after the invoice was made: its checks stopped.
     */
    public const ABANDONED = 'abandoned';

    /**
     * Not an error of the SDK: a fault of the module, or of Magento.
     */
    public const UNEXPECTED = 'error';

    /**
     * The code of $error: the SDK's (invalid_key, network…), or one of this class's.
     *
     * @param \Throwable $error The error.
     * @return string
     */
    public function code(\Throwable $error): string
    {
        if ($error instanceof SattlemeException) {
            return $error->getErrorCode();
        }
        if ($error instanceof CancelledException) {
            return self::TIMEOUT;
        }
        if ($error instanceof UnsupportedEnvironmentException) {
            return self::ENVIRONMENT;
        }

        return self::UNEXPECTED;
    }

    /**
     * $error for the log: the SDK's code and message (which never hold a secret).
     *
     * Else only what kind of error it was: an unexpected exception's message or trace could hold
     * anything.
     *
     * @param \Throwable $error The error.
     * @return string
     */
    public function describe(\Throwable $error): string
    {
        if ($error instanceof SattlemeException) {
            return $error->getErrorCode() . ': ' . $error->getMessage();
        }
        if ($error instanceof CancelledException) {
            return self::TIMEOUT . ': the call took too long';
        }
        if ($error instanceof UnsupportedEnvironmentException) {
            // It lists what PHP lacks (extensions, the curve), its version and OpenSSL's: no secret.
            return self::ENVIRONMENT . ': ' . $error->getMessage();
        }

        $file = $error->getFile();
        $slash = strrpos($file, '/');
        $name = $slash === false ? $file : substr($file, $slash + 1);

        return self::UNEXPECTED . ': ' . get_class($error) . ' at ' . $name . ':' . $error->getLine();
    }

    /**
     * Whether the same request may succeed a moment later.
     *
     * A relay, a rate source or an explorer that did not answer, a lot of addresses that is empty
     * for now, a call out of time.
     *
     * @param \Throwable $error The error.
     * @return bool
     */
    public function isTransient(\Throwable $error): bool
    {
        return ($error instanceof SattlemeException && $error->isRetryable()) || $error instanceof CancelledException;
    }

    /**
     * Whether the order itself cannot be paid with bitcoin: its amount or its currency.
     *
     * @param \Throwable $error The error.
     * @return bool
     */
    public function isOrderError(\Throwable $error): bool
    {
        return $error instanceof InvalidOrderException || $error instanceof UnsupportedCurrencyException;
    }

    /**
     * The merchant's words for a stored code (the order's view in the admin).
     *
     * @param string $code The code.
     * @return Phrase|null Null for no error.
     */
    public function explain(string $code): ?Phrase
    {
        $network = __(
            'Your relays, Lightning address, Bitcoin node, a rate source or an explorer did not answer. Checks go on.'
        );
        $explained = [
            'network' => $network,
            self::TIMEOUT => __('The check took too long. Checks go on.'),
            'rate' => __('No usable exchange rate. Try again later.'),
            'onchain_unavailable' => __(
                'No deposit address was free: open the sattle.me app to fill the integration\'s addresses again.'
            ),
            'invalid_key' => __('The integration key is not valid: enter the key of the integration again.'),
            'revoked' => __(
                'The integration was removed in the sattle.me app: create a new one and enter its key.'
            ),
            'channel_not_found' => __(
                'The integration was not found on its relays: check the key, or update the module.'
            ),
            'invalid_invoice' => __(
                'Your Lightning address answered with an invoice or a payment that failed the checks.'
            ),
            'invalid_order' => __(
                'The SDK refused the order or the stored invoice (amount, currency or reference).'
            ),
            'unsupported_currency' => __('No rate source has a price in the order\'s currency.'),
            self::ENVIRONMENT => __(
                'The server\'s PHP lacks what the module needs (see var/log/sattleme_payment.log).'
            ),
            self::APPLY => __('Magento could not update the order. Checks go on.'),
            self::DEADLINE => __(
                'The invoice would have ended after the order is cancelled as unpaid '
                . '(Pending Payment Order Lifetime): it was never shown.'
            ),
            self::MISMATCH => __(
                'The invoice made earlier for this attempt was not of the order\'s price: it was never shown.'
            ),
            self::LOCK => __('Another request was working on this order.'),
            self::NO_KEY => __('No integration key is set.'),
            self::PRICE => __('The order\'s total has more decimals than its currency has.'),
            self::ABANDONED => __(
                'No final status after 90 days: the checks stopped. Check this payment in the sattle.me app.'
            ),
        ];
        if ($code === '') {
            return null;
        }

        return $explained[$code] ?? __('Error: %1.', $code);
    }
}
