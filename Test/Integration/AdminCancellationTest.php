<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\Checker;
use Sattleme\Payment\Test\Support\Sdk;

/**
 * In the admin: no cancellation while a bitcoin payment may still come; once an underpayment
 * came and the merchant released the order from hold, the merchant may cancel it to settle it.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class AdminCancellationTest extends OrderTestCase
{
    public function testTheAdminCannotCancelWhileTheInvoiceMayBePaid(): void
    {
        $order = $this->placeOrder();
        $this->invoiceFor($order);

        $this->assertFalse($this->order($this->idOf($order))->canCancel());
    }

    public function testTheAdminMayCancelAnUnderpaidOrderItReleasedFromHold(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::open((int) $attempt->expiresAt, '20000', '39000')];
        $this->check($attempt);
        $this->assertFalse($this->order($this->idOf($order))->canCancel(), 'Its rest may still be paid');
        $this->clock()->advance(900 + Attempt::GRACE_SECONDS);
        $this->sdk()->statuses = [Sdk::expired('20000')];
        $this->check($attempt);
        $held = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_HOLDED, $held->getState());
        $held->unhold();
        $this->save($held);

        $released = $this->order($this->idOf($order));
        $this->assertTrue($released->canCancel());
        $released->cancel();
        $this->save($released);
        $this->assertSame(Order::STATE_CANCELED, $this->order($this->idOf($order))->getState());
    }

    /**
     * Checks $attempt now, as the cron does.
     *
     * @param Attempt $attempt The attempt.
     * @return bool
     */
    private function check(Attempt $attempt): bool
    {
        return $this->get(Checker::class)->check($attempt->id, 0, 10);
    }
}
