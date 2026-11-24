<?php

/**
 * The end-to-end test's switch (tools/tests/e2e.sh): a module of its own, installed only in the
 * Magento of that test, never in the package, that replaces the SDK with a fake.
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Sattleme_PaymentE2e', __DIR__);
