<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * A test of the module on a real Magento (OrderFixtures).
 */
abstract class OrderTestCase extends TestCase
{
    use OrderFixtures;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModule();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->tearDownModule();
        parent::tearDown();
    }
}
