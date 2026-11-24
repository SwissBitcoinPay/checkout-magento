<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\Exception\LocalizedException;

/**
 * An order's cancellation refused as it was carried out (Order::cancel(),
 * Order::registerCancellation()) by OrderCancelGuard: its invoice is being made, may still be paid,
 * or was. Thrown before the order changes, so that what its caller does after a cancellation
 * (Magento's e-mail to the customer, its comments, its save) is never done; Magento's paths show it
 * as they show their own refusals.
 */
class CancellationRefused extends LocalizedException
{
}
