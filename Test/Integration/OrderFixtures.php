<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Directory\Model\Region;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\Data\PaymentInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager as TestObjectManager;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\AttemptRepository;
use Sattleme\Payment\Model\Config;
use Sattleme\Payment\Model\DatabaseClock;
use Sattleme\Payment\Model\InvoiceCreator;
use Sattleme\Payment\Model\Orders;
use Sattleme\Payment\Model\SystemClock;
use Sattleme\Payment\Sdk\SdkClientProvider;
use Sattleme\Payment\Test\Support\FakeCheckoutClient;
use Sattleme\Payment\Test\Support\FakeClients;
use Sattleme\Payment\Test\Support\FakeClock;
use Sattleme\Payment\Test\Support\FakeDatabaseClock;
use Sattleme\Payment\Test\Support\Sdk;

/**
 * Tests on a real Magento (its integration test framework, its database): orders placed through
 * Magento's own checkout, the SDK faked at the module's seam (Sdk\ClientProviderInterface) and the
 * clock faked (Model\ClockInterface), both through Magento's object manager.
 *
 * The fakes are the same objects for the whole run (every instance of the module gets them),
 * emptied before each test; the module's own instances are made again for each test.
 */
trait OrderFixtures
{
    /*
     * The properties are nullable: Magento's integration test framework sets every property of a
     * test to null after its class's tests (Workaround\Cleanup\TestCaseProperties).
     */

    /**
     * @var FakeClock|null
     */
    private static ?FakeClock $sharedClock = null;

    /**
     * @var FakeClients|null
     */
    private static ?FakeClients $sharedClients = null;

    /**
     * @var FakeDatabaseClock|null
     */
    private static ?FakeDatabaseClock $sharedDatabaseClock = null;

    /**
     * @var TestObjectManager|null
     */
    private ?TestObjectManager $testObjectManager = null;

    /**
     * The settings a test changed, to restore: [path, value before, store].
     *
     * @var array<string, array{0: string, 1: mixed, 2: string}>|null
     */
    private ?array $changedConfig = [];

    /**
     * Registers the fakes, and sets the method up: enabled, with a key.
     *
     * @return void
     */
    protected function setUpModule(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        if (!$objectManager instanceof TestObjectManager) {
            $this->fail('Not the test framework\'s object manager');
        }
        $this->testObjectManager = $objectManager;
        self::$sharedClock ??= new FakeClock();
        self::$sharedClients ??= new FakeClients(self::$sharedClock);
        $databaseClock = $objectManager->create(FakeDatabaseClock::class);
        $this->assertInstanceOf(FakeDatabaseClock::class, $databaseClock);
        self::$sharedDatabaseClock ??= $databaseClock;
        $this->clock()->set(time());
        $this->databaseClock()->reset();
        $this->clients()->reset();
        $objectManager->addSharedInstance($this->clock(), SystemClock::class);
        $objectManager->addSharedInstance($this->databaseClock(), DatabaseClock::class);
        $objectManager->addSharedInstance($this->clients(), SdkClientProvider::class);
        foreach ($this->moduleInstances() as $class) {
            $objectManager->removeSharedInstance($class);
        }
        $this->changedConfig = [];
        $this->setConfig(Config::PATH_ACTIVE, '1');
        $this->setConfig(Config::PATH_KEY, Sdk::KEY);
        $this->setConfig(Config::PATH_MINUTES, '15');
        $this->setConfig(Config::PATH_PENDING_LIFETIME, '480');
    }

    /**
     * Restores the settings the test changed.
     *
     * @return void
     */
    protected function tearDownModule(): void
    {
        $config = $this->get(MutableScopeConfigInterface::class);
        foreach (array_reverse($this->changedConfig ?? []) as [$path, $value, $store]) {
            $config->setValue($path, $value, ScopeInterface::SCOPE_STORE, $store);
        }
        $this->changedConfig = [];
    }

    /**
     * The module's classes that hold a fake, a setting or a cache: made again for each test.
     *
     * @return list<class-string>
     */
    private function moduleInstances(): array
    {
        return [
            \Sattleme\Payment\Model\Availability::class,
            \Sattleme\Payment\Model\Background::class,
            \Sattleme\Payment\Model\Checker::class,
            \Sattleme\Payment\Model\GiveUp::class,
            \Sattleme\Payment\Model\InvoiceCreator::class,
            \Sattleme\Payment\Model\KeyVerifier::class,
            \Sattleme\Payment\Model\Notes::class,
            \Sattleme\Payment\Model\OrderLock::class,
            \Sattleme\Payment\Model\OrderMarks::class,
            \Sattleme\Payment\Model\Page\CurrentOrder::class,
            \Sattleme\Payment\Model\Page\Progress::class,
            \Sattleme\Payment\Model\Settlement::class,
            \Sattleme\Payment\Model\Ui\ConfigProvider::class,
            \Sattleme\Payment\Observer\MethodAvailability::class,
            \Sattleme\Payment\Plugin\OrderCancelGuard::class,
            \Sattleme\Payment\ViewModel\PaymentPage::class,
        ];
    }

    /**
     * The test framework's object manager.
     *
     * @return TestObjectManager
     */
    protected function om(): TestObjectManager
    {
        $this->assertNotNull($this->testObjectManager, 'setUpModule() ran');

        return $this->testObjectManager;
    }

    /**
     * The fake clock every instance of the module has.
     *
     * @return FakeClock
     */
    protected function clock(): FakeClock
    {
        $this->assertNotNull(self::$sharedClock, 'setUpModule() ran');

        return self::$sharedClock;
    }

    /**
     * The database's clock every instance of the module has, the database's own time at first.
     *
     * @return FakeDatabaseClock
     */
    protected function databaseClock(): FakeDatabaseClock
    {
        $this->assertNotNull(self::$sharedDatabaseClock, 'setUpModule() ran');

        return self::$sharedDatabaseClock;
    }

    /**
     * Moves the time on, by the servers' clock and the database's.
     *
     * @param int $seconds Seconds.
     * @return void
     */
    protected function advance(int $seconds): void
    {
        $this->clock()->advance($seconds);
        $this->databaseClock()->advance($seconds);
    }

    /**
     * The SDK's fake clients every instance of the module has.
     *
     * @return FakeClients
     */
    protected function clients(): FakeClients
    {
        $this->assertNotNull(self::$sharedClients, 'setUpModule() ran');

        return self::$sharedClients;
    }

    /**
     * Magento's shared instance of $class.
     *
     * @template T of object
     * @param class-string<T> $class The class.
     * @return T
     */
    protected function get(string $class): object
    {
        $instance = $this->om()->get($class);
        $this->assertInstanceOf($class, $instance);

        return $instance;
    }

    /**
     * A new instance of $class, made by Magento's object manager with $arguments.
     *
     * @template T of object
     * @param class-string<T> $class The class.
     * @param array<string,mixed> $arguments Its arguments.
     * @return T
     */
    protected function make(string $class, array $arguments = []): object
    {
        $instance = $this->om()->create($class, $arguments);
        $this->assertInstanceOf($class, $instance);

        return $instance;
    }

    /**
     * Sets $path to $value in store $store for this test.
     *
     * @param string $path The setting's path.
     * @param string|null $value Its value.
     * @param string $store The store's code.
     * @return void
     */
    protected function setConfig(string $path, ?string $value, string $store = 'default'): void
    {
        $config = $this->get(MutableScopeConfigInterface::class);
        $key = $path . '@' . $store;
        if (!isset($this->changedConfig[$key])) {
            $this->changedConfig[$key] = [$path, $config->getValue($path, ScopeInterface::SCOPE_STORE, $store), $store];
        }
        $config->setValue($path, $value, ScopeInterface::SCOPE_STORE, $store);
    }

    /**
     * The fake SDK client every key gets.
     *
     * @return FakeCheckoutClient
     */
    protected function sdk(): FakeCheckoutClient
    {
        return $this->clients()->client;
    }

    /**
     * A product of $price, in stock.
     *
     * @param float $price Its price.
     * @return Product
     */
    protected function product(float $price = 24.5): Product
    {
        $sku = 'ticket-' . bin2hex(random_bytes(6));
        $product = $this->make(Product::class);
        $product->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId(4)
            ->setName('Ticket ' . $sku)
            ->setSku($sku)
            ->setPrice($price)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(ProductStatus::STATUS_ENABLED)
            ->setWeight(1);
        $product->setCustomAttribute('url_key', $sku);
        $product->setData('website_ids', [1]);
        $product->setData(
            'stock_data',
            ['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1]
        );

        $saved = $this->get(ProductRepositoryInterface::class)->save($product);
        $this->assertInstanceOf(Product::class, $saved);

        return $saved;
    }

    /**
     * A guest's cart of one product of $price, shipped at the flat rate (5.00), in store 1.
     *
     * @param float $price The product's price.
     * @return Quote
     */
    protected function cart(float $price = 24.5): Quote
    {
        $product = $this->product($price);
        $quote = $this->get(QuoteFactory::class)->create();
        $quote->setStoreId(1)
            ->setIsActive(true)
            ->setIsMultiShipping(0)
            ->setCheckoutMethod(CartManagementInterface::METHOD_GUEST)
            ->setCustomerEmail('ada@example.com');
        $quote->addProduct($product, 1);
        $address = [
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'street' => 'Rue du Lac 1',
            'city' => 'Lausanne',
            'postcode' => '1003',
            'country_id' => 'CH',
            'region_id' => $this->idOfModel($this->make(Region::class)->loadByCode('VD', 'CH')),
            'telephone' => '0210000000',
            'email' => 'ada@example.com',
        ];
        $quote->getBillingAddress()->addData($address);
        $shipping = $quote->getShippingAddress();
        $shipping->addData($address);
        $shipping->setCollectShippingRates(true);
        $shipping->collectShippingRates();
        $shipping->setShippingMethod('flatrate_flatrate');
        $quote->collectTotals();
        $this->get(CartRepositoryInterface::class)->save($quote);

        return $quote;
    }

    /**
     * An order of one product of $price (and 5.00 of shipping) placed with this method through
     * Magento's checkout, as the database holds it.
     *
     * @param float $price The product's price.
     * @return Order
     */
    protected function placeOrder(float $price = 24.5): Order
    {
        $quote = $this->cart($price);
        $orderId = $this->get(CartManagementInterface::class)
            ->placeOrder($this->idOfModel($quote), $this->paymentMethod());

        return $this->order($this->intOf($orderId));
    }

    /**
     * Order $orderId as the database holds it now.
     *
     * @param int $orderId The order.
     * @return Order
     */
    protected function order(int $orderId): Order
    {
        $order = $this->get(Orders::class)->fresh($orderId);
        $this->assertNotNull($order, 'Order ' . $orderId . ' exists');

        return $order;
    }

    /**
     * The id of $order.
     *
     * @param Order $order The order.
     * @return int
     */
    protected function idOf(Order $order): int
    {
        return $this->get(Orders::class)->idOf($order);
    }

    /**
     * $value, a number as Magento gives one (its PHPDoc says int, its database a string), as an int.
     *
     * @param mixed $value The value.
     * @return int
     */
    protected function intOf(mixed $value): int
    {
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * $value, a text as Magento gives one (its PHPDoc says string, its database may hold null).
     *
     * @param mixed $value The value.
     * @return string
     */
    protected function textOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The id of $model, a model Magento saved.
     *
     * @param \Magento\Framework\Model\AbstractModel $model The model.
     * @return int
     */
    protected function idOfModel(\Magento\Framework\Model\AbstractModel $model): int
    {
        $id = $model->getId();
        $this->assertIsNumeric($id);

        return (int) $id;
    }

    /**
     * Makes the invoice of $order (InvoiceCreator::invoiceFor), as the customer's page does.
     *
     * @param Order $order The order.
     * @return Attempt
     */
    protected function invoiceFor(Order $order): Attempt
    {
        return $this->get(InvoiceCreator::class)->invoiceFor($this->idOf($order));
    }

    /**
     * The attempts' repository.
     *
     * @return AttemptRepository
     */
    protected function attempts(): AttemptRepository
    {
        return $this->get(AttemptRepository::class);
    }

    /**
     * The attempts of $order, the latest first.
     *
     * @param Order $order The order.
     * @return list<Attempt>
     */
    protected function attemptsOf(Order $order): array
    {
        return $this->attempts()->forOrder($this->idOf($order));
    }

    /**
     * The comments of $order's history, the oldest first.
     *
     * @param Order $order The order.
     * @return list<string>
     */
    protected function notes(Order $order): array
    {
        $notes = [];
        $history = $this->order($this->idOf($order))->getStatusHistoryCollection();
        foreach ($history as $item) {
            $this->assertInstanceOf(Order\Status\History::class, $item);
            $notes[$this->idOfModel($item)] = $this->textOf($item->getComment());
        }
        ksort($notes);

        return array_values($notes);
    }

    /**
     * Saves $order (Magento's repository).
     *
     * @param Order $order The order.
     * @return void
     */
    protected function save(Order $order): void
    {
        $this->get(OrderRepositoryInterface::class)->save($order);
    }

    /**
     * The payment method for a checkout: this one.
     *
     * @return PaymentInterface
     */
    protected function paymentMethod(): PaymentInterface
    {
        $payment = $this->get(PaymentInterfaceFactory::class)->create();
        $payment->setMethod(Config::CODE);

        return $payment;
    }
}
