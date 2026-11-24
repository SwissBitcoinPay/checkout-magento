<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\LockUnavailable;
use Sattleme\Payment\Model\OrderLock;

/**
 * The order's lock: Magento's lock manager, released whatever happens, re-entrant in a process.
 */
class OrderLockTest extends TestCase
{
    public function testTheWorkRunsWithTheOrderLockedThenReleased(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('lock')->with('sattleme_payment_order_42', 25)->willReturn(true);
        $locks->expects($this->once())->method('unlock')->with('sattleme_payment_order_42')->willReturn(true);
        $lock = new OrderLock($locks);

        $this->assertSame('done', $lock->run(42, 25, fn(float $waited): string => 'done'));
    }

    public function testTheLockIsReleasedWhenTheWorkFails(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->expects($this->once())->method('unlock');
        $lock = new OrderLock($locks);

        $this->expectException(\DomainException::class);
        $lock->run(42, 25, static function (): void {
            throw new \DomainException('Failed');
        });
    }

    public function testAnOrderLockedElsewhereTooLongIsNotWorkedOn(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(false);
        $locks->expects($this->never())->method('unlock');
        $lock = new OrderLock($locks);
        $ran = false;

        try {
            $lock->run(42, 1, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('No exception');
        } catch (LockUnavailable $e) {
            $this->assertSame('Order 42 is locked by another request', $e->getMessage());
        }
        $this->assertFalse($ran);
    }

    public function testTheLockIsReentrantInAProcess(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('lock')->willReturn(true);
        $locks->expects($this->once())->method('unlock');
        $lock = new OrderLock($locks);

        $inner = $lock->run(42, 25, fn(): float => $lock->run(42, 25, fn(float $waited): float => $waited));

        $this->assertSame(0.0, $inner);
    }
}
