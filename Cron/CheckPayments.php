<?php

declare(strict_types=1);

namespace Sattleme\Payment\Cron;

use Sattleme\Payment\Model\Background;

/**
 * The cron job sattleme_payment_check (etc/crontab.xml): the background checks, every minute.
 */
class CheckPayments
{
    /**
     * @param Background $background The background checks.
     */
    public function __construct(private readonly Background $background)
    {
    }

    /**
     * One run.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->background->run();
    }
}
