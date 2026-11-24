<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * An invoice's duration: the merchant's time to pay, but never past the order's deadline (the
 * moment Magento would cancel it as unpaid, and free its stock), its grace period included.
 *
 * The SDK dates an invoice when the merchant's Lightning address makes it, up to CREATE_TIMEOUT
 * after the duration is computed: it counts from then, and must still end, with the SDK's grace
 * period (120 s: a payment of the invoice's last seconds is reported up to then), by the
 * deadline. Once made, the invoice is checked against the deadline again (the clock of the
 * merchant's Lightning address may be up to 300 s off): one that ends later is never shown.
 */
class Durations
{
    /**
     * The SDK's createInvoice, as a whole, in seconds: it runs while the customer waits.
     */
    public const CREATE_TIMEOUT = 20;

    /**
     * The SDK's bounds of an invoice's duration, in seconds.
     */
    public const MIN_SECONDS = 60;

    public const MAX_SECONDS = 3600;

    /**
     * The merchant's time to pay, in minutes: its bounds and its default.
     */
    public const MIN_MINUTES = 1;

    public const MAX_MINUTES = 60;

    public const DEFAULT_MINUTES = 15;

    /**
     * The seconds an invoice is asked for: $minutes, bounded by $deadlineLeft.
     *
     * $deadlineLeft: the seconds until the order's deadline, null without one. Null when too
     * little is left to pay.
     *
     * @param int $minutes The time to pay, in minutes.
     * @param int|null $deadlineLeft Seconds before the order is cancelled, null when nothing bounds it.
     * @return int|null
     */
    public function seconds(int $minutes, ?int $deadlineLeft): ?int
    {
        $seconds = max(self::MIN_SECONDS, min(self::MAX_SECONDS, $minutes * 60));
        if ($deadlineLeft !== null) {
            $left = $deadlineLeft - Attempt::GRACE_SECONDS - self::CREATE_TIMEOUT;
            if ($left < self::MIN_SECONDS) {
                return null;
            }
            $seconds = min($seconds, $left);
        }

        return $seconds;
    }

    /**
     * Whether an invoice ending at $expiresAt, with its grace period, ends by $deadline.
     *
     * $deadline: Unix seconds, null without one.
     *
     * @param int $expiresAt When the invoice ends (Unix seconds).
     * @param int|null $deadline When it must end by (Unix seconds), null when nothing bounds it.
     * @return bool
     */
    public function endsInTime(int $expiresAt, ?int $deadline): bool
    {
        return $deadline === null || $expiresAt + Attempt::GRACE_SECONDS <= $deadline;
    }

    /**
     * The merchant's time to pay, in minutes, as configured: within its bounds, else the default.
     *
     * @param mixed $configured The configured value.
     * @return int
     */
    public function minutes(mixed $configured): int
    {
        if (is_int($configured) || (is_string($configured) && preg_match('/^\s*[0-9]{1,3}\s*$/D', $configured) === 1)) {
            $minutes = (int) $configured;
            if ($minutes >= self::MIN_MINUTES && $minutes <= self::MAX_MINUTES) {
                return $minutes;
            }
        }

        return self::DEFAULT_MINUTES;
    }
}
