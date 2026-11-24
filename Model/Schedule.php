<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * The rhythm of an invoice's background checks (the SDK has no webhooks):
 *
 * - every minute while it can be paid, once just before the end of its grace period (an on-chain
 *   payment a check lists by then came in time, SPEC.md §31) and once just after (its final
 *   status), whatever failed;
 * - then every five minutes until its final status is known (an on-chain payment's
 *   confirmations); after failed checks, twice as long each time, up to every 30 minutes;
 * - given up 90 days after it was made.
 *
 * Magento's cron runs every minute at best: a check is due at a time, and runs at the first run
 * of the cron from then. The next check is counted from the start of the run that checked (never
 * from the end of its check), less a few seconds (SLACK): a check due a minute later is due before
 * the next run starts, which a run that starts a little early, or a check that took its time,
 * would otherwise make wait for the run after it.
 */
class Schedule
{
    /**
     * Seconds between two checks of an invoice that can still be paid.
     */
    public const OPEN_INTERVAL = 60;

    /**
     * Seconds between two checks of one past its end whose final status is unknown.
     */
    public const SETTLING_INTERVAL = 300;

    /**
     * The last check of the grace period is due this long before its end: Magento's cron runs it
     * at its next run, within a minute, still in the grace period.
     */
    public const BEFORE_END = 75;

    /**
     * The first check after the grace period (the final status) is due this long after its end.
     */
    public const AFTER_END = 10;

    /**
     * A check asked by the customer's page runs at most this often per invoice (seconds).
     */
    public const WEB_INTERVAL = 6;

    /**
     * After failed checks, the next waits twice as long each time, up to this (seconds).
     */
    public const MAX_BACKOFF = 1800;

    /**
     * A check due after an interval is due this much sooner (seconds): by the next run of the cron.
     */
    public const SLACK = 10;

    /**
     * An invoice whose final status is unknown this long after it was made is given up on.
     */
    public const GIVE_UP_AFTER = 90 * 86400;

    /**
     * When the next background check of $attempt is due (Unix seconds).
     *
     * $attempt was checked by the run of the cron that started at $start.
     *
     * @param Attempt $attempt The attempt.
     * @param int $start When the run started, in Unix seconds.
     * @return int
     */
    public function nextCheckAt(Attempt $attempt, int $start): int
    {
        $end = $attempt->end();
        if ($start < $end + self::AFTER_END) {
            // A check just before the end, and one just after: the next of them that is to come,
            // and every minute until then.
            $mark = $end - self::BEFORE_END > $start ? $end - self::BEFORE_END : $end + self::AFTER_END;

            return max($start + self::WEB_INTERVAL, min($mark, $start + self::OPEN_INTERVAL - self::SLACK));
        }
        if ($attempt->errors > 0) {
            $backoff = min(self::MAX_BACKOFF, self::OPEN_INTERVAL * (2 ** min($attempt->errors - 1, 10)));

            return $start + $backoff - self::SLACK;
        }

        return $start + self::SETTLING_INTERVAL - self::SLACK;
    }
}
