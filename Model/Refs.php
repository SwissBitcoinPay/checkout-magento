<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * The SDK's order reference of each attempt to pay, and the note its merchant's app shows.
 */
class Refs
{
    /**
     * The SDK's longest note, in code points.
     */
    public const NOTE_MAX = 100;

    /**
     * A new reference for an attempt to pay the order $incrementId.
     *
     * "m2-<order number>-<32 hex digits>", 128 bits from the CSPRNG, made on the server.
     *
     * Random, so that no two attempts ever share one, whatever holds the key: a test shop whose
     * order numbers start again, a restored backup, a copy of the shop. The SDK would otherwise
     * hand out an earlier invoice of the reference, or report it paid. The order's number only
     * helps the merchant find the order (its characters outside the SDK's are left out).
     *
     * @param string $incrementId The order's number.
     * @return string
     */
    public function newRef(string $incrementId): string
    {
        $number = substr((string) preg_replace('/[^A-Za-z0-9._:-]/', '', $incrementId), 0, 24);
        $random = bin2hex(random_bytes(16));

        return $number === '' ? 'm2-' . $random : 'm2-' . $number . '-' . $random;
    }

    /**
     * Whether $ref is a reference as the SDK takes them: 1 to 64 characters A-Z a-z 0-9 . _ : -.
     *
     * @param string $ref The reference.
     * @return bool
     */
    public function isRef(string $ref): bool
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $ref) === 1;
    }

    /**
     * What the merchant's app shows of the sale (and, when the Lightning address takes comments,
     * the customer's wallet): the shop's name and "Order <number>" ($order, translated), the
     * SDK's rules met (no control character, 100 code points at most).
     *
     * @param string $shop The shop's name.
     * @param string $order "Order <number>", in the shop's language.
     * @return string
     */
    public function note(string $shop, string $order): string
    {
        $order = $this->clean($order);
        $shop = $this->clean($shop);
        if ($shop === '') {
            return $this->cut($order, self::NOTE_MAX);
        }
        $suffix = ' · ' . $order;
        $room = self::NOTE_MAX - mb_strlen($suffix, 'UTF-8');
        if ($room < 8) {
            return $this->cut($order, self::NOTE_MAX);
        }
        if (mb_strlen($shop, 'UTF-8') > $room) {
            $shop = rtrim($this->cut($shop, $room - 1)) . '…';
        }

        return $shop . $suffix;
    }

    /**
     * $text as valid UTF-8 without control characters, its whitespace runs as one space.
     *
     * @param string $text The text.
     * @return string
     */
    private function clean(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The first $length code points of $text.
     *
     * @param string $text The text.
     * @param int $length The length.
     * @return string
     */
    private function cut(string $text, int $length): string
    {
        return mb_substr($text, 0, $length, 'UTF-8');
    }
}
