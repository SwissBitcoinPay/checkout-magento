<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * Another request held the order's lock for longer than this one could wait.
 */
class LockUnavailable extends \RuntimeException
{
}
