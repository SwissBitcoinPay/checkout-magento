<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sattleme\Payment\Model\Availability;
use Sattleme\Payment\Model\Config;
use Sattleme\Payment\Model\Durations;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Sdk\Prices;
use Sattleme\Payment\Test\Support\FakeClients;
use Sattleme\Payment\Test\Support\FakeClock;
use Sattleme\Payment\Test\Support\Sdk;

/**
 * Which carts the method is offered for: a key the SDK takes, a currency it converts, a total it
 * takes exactly.
 */
class AvailabilityTest extends TestCase
{
    public function testACartInACurrencyTheSdkConvertsIsOffered(): void
    {
        $this->assertTrue($this->availability(Sdk::KEY)->isAvailable($this->quote('CHF', 29.5)));
        $this->assertTrue($this->availability(Sdk::KEY)->isAvailable($this->quote('JPY', 1200.0)));
        $this->assertTrue($this->availability(Sdk::KEY)->isAvailable($this->quote('KWD', 1.234)));
    }

    public function testACartInACurrencyTheSdkDoesNotConvertIsNotOffered(): void
    {
        $this->assertFalse($this->availability(Sdk::KEY)->isAvailable($this->quote('XAU', 29.5)));
        $this->assertFalse($this->availability(Sdk::KEY)->isAvailable($this->quote('', 29.5)));
    }

    public function testATotalWithMoreDecimalsThanItsCurrencyIsNotOffered(): void
    {
        $this->assertFalse($this->availability(Sdk::KEY)->isAvailable($this->quote('JPY', 1200.5)));
        $this->assertFalse($this->availability(Sdk::KEY)->isAvailable($this->quote('CHF', 0.0)));
    }

    public function testWithoutAKeyTheSdkTakesNothingIsOffered(): void
    {
        $this->assertFalse($this->availability('')->isAvailable($this->quote('CHF', 29.5)));
        $this->assertFalse($this->availability('sk_live_not_ours')->isAvailable($this->quote('CHF', 29.5)));
    }

    /**
     * The availability, store 1's key being $key.
     *
     * @param string $key The key.
     * @return Availability
     */
    private function availability(string $key): Availability
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path): ?string => $path === Config::PATH_KEY ? $key : null
        );

        return new Availability(
            new Config($scopeConfig, new Durations()),
            new FakeClients(new FakeClock(1_800_000_000)),
            new Prices(),
            new Errors(),
            new NullLogger()
        );
    }

    /**
     * A cart of store 1 in $currency, of $total.
     *
     * @param string $currency The currency the customer pays in.
     * @param float $total Its total.
     * @return Quote
     */
    private function quote(string $currency, float $total): Quote
    {
        $currencyData = $this->createStub(CurrencyInterface::class);
        $currencyData->method('getQuoteCurrencyCode')->willReturn($currency);
        // A cart as Magento loads it: its data only (no repository, no store manager needed).
        $quote = (new \ReflectionClass(Quote::class))->newInstanceWithoutConstructor();
        $quote->setData(['store_id' => 1, 'grand_total' => $total, 'currency' => $currencyData]);

        return $quote;
    }
}
