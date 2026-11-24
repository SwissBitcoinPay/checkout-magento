<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Psr\Log\LoggerInterface;

/**
 * The background checks (the SDK has no webhooks), run by Magento's cron every minute: the
 * invoices whose check is due (Schedule), the longest due first, each check bounded in time, the
 * run bounded in number of invoices and in time; then the invoices whose final status is still
 * unknown 90 days after they were made are given up on, with a note on their order.
 */
class Background
{
    /**
     * Invoices checked per run, at most.
     */
    public const BATCH = 20;

    /**
     * A run starts no new check this long after it started (seconds): it ends before the next.
     */
    public const RUN_SECONDS = 45;

    /**
     * One check, at most (seconds).
     */
    public const CHECK_TIMEOUT = 20;

    /**
     * Invoices given up on per run, at most.
     */
    public const GIVE_UP_BATCH = 50;

    /**
     * @param AttemptRepository $attempts The attempts.
     * @param Checker $checker The checks.
     * @param GiveUp $giveUp What gives up on an invoice.
     * @param Schedule $schedule When invoices are checked.
     * @param ClockInterface $clock The clock.
     * @param LoggerInterface $logger The module's log.
     */
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly Checker $checker,
        private readonly GiveUp $giveUp,
        private readonly Schedule $schedule,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * One run: how many invoices it checked.
     *
     * @return int
     */
    public function run(): int
    {
        $start = $this->clock->now();
        foreach ($this->attempts->olderThan($start - Schedule::GIVE_UP_AFTER, self::GIVE_UP_BATCH) as $attempt) {
            $this->giveUp->attempt($attempt);
        }
        $checked = 0;
        foreach ($this->attempts->due($start, self::BATCH) as $attempt) {
            if ($this->clock->now() - $start >= self::RUN_SECONDS) {
                break;
            }
            try {
                $this->checker->check($attempt->id, 0, self::CHECK_TIMEOUT);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('The background check of invoice %s failed: %s', $attempt->ref, get_class($e))
                );
            }
            ++$checked;
            $this->scheduleNext($attempt->id, $start);
        }

        return $checked;
    }

    /**
     * Schedules the next check of attempt $id, from what its check left.
     *
     * Counted from the start of the run (Schedule::nextCheckAt()).
     *
     * @param int $id The attempt.
     * @param int $start When the run started.
     * @return void
     */
    private function scheduleNext(int $id, int $start): void
    {
        $attempt = $this->attempts->find($id);
        if ($attempt === null || $attempt->isFinal) {
            return;
        }
        $this->attempts->scheduleNext($id, $this->schedule->nextCheckAt($attempt, $start));
    }
}
