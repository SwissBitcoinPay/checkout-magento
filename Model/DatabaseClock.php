<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * The database's clock, in Unix seconds: one clock for every web server and cron process of the
 * shop, whatever their own clocks say. The marks of an order (OrderMarks) are written and read
 * with it, so that a server whose clock is behind or ahead never shortens their bounds.
 */
class DatabaseClock
{
    /**
     * @param ResourceConnection $resource The database.
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Now, by the database's clock.
     *
     * @return int
     */
    public function now(): int
    {
        return (int) $this->resource->getConnection(AttemptRepository::CONNECTION)->fetchOne('SELECT UNIX_TIMESTAMP()');
    }
}
