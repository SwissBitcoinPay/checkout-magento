<?php

declare(strict_types=1);

namespace Sattleme\Payment\Sdk;

use Sattleme\Payment\Vendor\Sattleme\Checkout\Client;

/**
 * The currencies the SDK converts, and an order's total as its price.
 *
 * The table is the SDK's own (checkout-spec's currencies.json, with each currency's decimals),
 * never the platform's locale data, read through its public Client::currencyDigits() (SPEC.md §4,
 * Functions): no client, no request. The module uses the SDK's public API alone (PricesTest).
 */
class Prices
{
    /**
     * The most minor units a price may have: 2^53 - 1, the largest integer every JSON reader
     * holds exactly (SPEC.md §7).
     */
    private const MAX_MINOR = '9007199254740991';

    /**
     * Whether the SDK converts prices in $currency (ISO 4217, upper case).
     *
     * @param string $currency The currency.
     * @return bool
     */
    public function isSupportedCurrency(string $currency): bool
    {
        return $this->digits($currency) !== null;
    }

    /**
     * The decimals of $currency in the SDK's table, or null when it is not in the table.
     *
     * $currency is exactly the code (upper case, nothing around it); any other text is in none.
     *
     * @param string $currency The currency.
     * @return int|null
     */
    public function digits(string $currency): ?int
    {
        return Client::currencyDigits($currency);
    }

    /**
     * An order's total (a decimal string, as Magento stores it: "24.5000") as the SDK's price in
     * $currency: exactly it, without trailing zeros.
     *
     * Null for a total the SDK refuses (SPEC.md §25, the price of an order): one that is not 1 to
     * 15 digits with an optional fraction of 1 to 15, one with more than the currency's decimals
     * once its trailing zeros are dropped, or one whose minor units are not from 1 to 2^53 - 1. A
     * total that cannot be paid exactly is never rounded.
     *
     * @param string $total The total.
     * @param string $currency The currency.
     * @return string|null
     */
    public function amount(string $total, string $currency): ?string
    {
        $digits = $this->digits($currency);
        if ($digits === null || preg_match('/^([0-9]{1,15})(?:\.([0-9]{1,15}))?$/D', $total, $match) !== 1) {
            return null;
        }
        $fraction = rtrim($match[2] ?? '', '0');
        if (strlen($fraction) > $digits) {
            return null;
        }
        // Digits without leading zeros: of two, the longer is the larger; of two as long, the
        // first digit that differs says.
        $minor = ltrim($match[1] . str_pad($fraction, $digits, '0'), '0');
        $longest = strlen(self::MAX_MINOR);
        if ($minor === '' || strlen($minor) > $longest
            || (strlen($minor) === $longest && strcmp($minor, self::MAX_MINOR) > 0)
        ) {
            return null;
        }
        $whole = ltrim($match[1], '0');
        $whole = $whole === '' ? '0' : $whole;

        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    /**
     * $total in $currency for the merchant to read: "35.50 USD".
     *
     * With the currency's decimals when that is exact, else as it is.
     *
     * @param string $total A decimal string.
     * @param string $currency The currency.
     * @return string
     */
    public function display(string $total, string $currency): string
    {
        $digits = $this->digits($currency);
        if ($digits !== null && is_numeric($total) && preg_match('/^[0-9]{1,15}(?:\.[0-9]{1,15})?$/D', $total) === 1) {
            $shown = bcadd($total, '0', $digits);
            if (bccomp($shown, $total, 15) === 0) {
                return $shown . ' ' . $currency;
            }
        }

        return $total . ' ' . $currency;
    }

    /**
     * A total as Magento holds it (a float, or a decimal string of the database) as a decimal.
     *
     * A string, without the float's noise: Magento keeps amounts with 4 decimals.
     *
     * @param mixed $total The total.
     * @return numeric-string|null Null when it is not a finite non-negative number.
     */
    public function decimal(mixed $total): ?string
    {
        if (is_string($total) && is_numeric($total) && preg_match('/^[0-9]{1,15}(?:\.[0-9]{1,15})?$/D', $total) === 1) {
            return $total;
        }
        if ((is_float($total) || is_int($total)) && is_finite((float) $total) && $total >= 0 && $total < 1e15) {
            return number_format((float) $total, 4, '.', '');
        }

        return null;
    }

    /**
     * Whether $fiat, the price of an invoice (the "fiat" of its JSON: amount and currency), is
     * exactly $total in $currency. An invoice pays an order only at the order's price: one made
     * for an earlier total does not.
     *
     * @param mixed $fiat The invoice's price.
     * @param string $total The order's total, a decimal string.
     * @param string $currency The order's currency.
     * @return bool
     */
    public function isPriceOf(mixed $fiat, string $total, string $currency): bool
    {
        if (!is_array($fiat) || !is_string($fiat['amount'] ?? null) || ($fiat['currency'] ?? null) !== $currency) {
            return false;
        }
        $price = $this->amount($total, $currency);

        return $price !== null && $this->amount($fiat['amount'], $currency) === $price;
    }
}
