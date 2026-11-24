<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * Amounts in sats and in bitcoin, as the customer and the merchant read them: digits only, from
 * the SDK's strings (never a float).
 */
class Amounts
{
    /**
     * $sats (a string of digits) with the grouping of $locale ("49,000", "49 000", "49’000").
     *
     * @param string $sats The sats.
     * @param string $locale The locale, such as "de_CH".
     * @return string
     */
    public function sats(string $sats, string $locale = 'en_US'): string
    {
        $sats = $this->digits($sats);
        if (strlen($sats) <= 15 && class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $formatted = $formatter->format((int) $sats);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return $sats;
    }

    /**
     * $sats in bitcoin, a plain decimal without trailing zeros ("0.00049").
     *
     * @param string $sats The sats.
     * @return string
     */
    public function btc(string $sats): string
    {
        $sats = str_pad($this->digits($sats), 9, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($sats, 0, -8), '0');
        $fraction = rtrim(substr($sats, -8), '0');

        return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }

    /**
     * Whether $a is less than $b (strings of digits).
     *
     * @param string $a One.
     * @param string $b The other.
     * @return bool
     */
    public function isLess(string $a, string $b): bool
    {
        $a = $this->digits($a);
        $b = $this->digits($b);

        return strlen($a) === strlen($b) ? strcmp($a, $b) < 0 : strlen($a) < strlen($b);
    }

    /**
     * A string of digits without leading zeros, "0" for anything else.
     *
     * @param string $value The value.
     * @return string
     */
    private function digits(string $value): string
    {
        if (preg_match('/^[0-9]{1,18}$/D', $value) !== 1) {
            return '0';
        }
        $value = ltrim($value, '0');

        return $value === '' ? '0' : $value;
    }
}
