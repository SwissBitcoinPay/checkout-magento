<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Checkout\Model\CompositeConfigProvider;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Config\Model\Config as ConfigModel;
use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Helper\Data as PaymentHelper;
use Sattleme\Payment\Model\Config;
use Sattleme\Payment\Model\MerchantInfo;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\RevokedException;

/**
 * The settings, saved as the admin's configuration page saves them: the key checked with the SDK,
 * stored encrypted, never shown nor logged; the time to pay; the checkout's title and description.
 *
 * Saved as Magento saves them, in a transaction of their own (one that fails is rolled back):
 * without the test's transaction, the settings it saved removed after it.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class SettingsTest extends OrderTestCase
{
    /**
     * Removes what the test saved.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $resource = $this->get(ResourceConnection::class);
        $resource->getConnection()->delete(
            $resource->getTableName('core_config_data'),
            ['path IN (?)' => [Config::PATH_KEY, Config::PATH_MERCHANT, Config::PATH_MINUTES]]
        );
        $this->get(ReinitableConfigInterface::class)->reinit();
        parent::tearDown();
    }

    public function testAKeyIsCheckedWhenSavedThenStoredEncrypted(): void
    {
        $this->saveKey(Sdk::KEY);

        $this->assertSame([Sdk::KEY], $this->clients()->keys);
        $this->assertSame(1, $this->sdk()->merchantCalls);
        $stored = $this->stored(Config::PATH_KEY);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(Sdk::KEY, $stored);
        $this->assertStringNotContainsString('sattleme_ck_', $stored);
        $this->assertSame(Sdk::KEY, $this->get(EncryptorInterface::class)->decrypt($stored));
        // Read decrypted (config.xml's backend model) once Magento reads its configuration again.
        $this->get(ReinitableConfigInterface::class)->reinit();
        $this->assertSame(Sdk::KEY, $this->get(Config::class)->integrationKey(1));
        // What the key reached: shown under it.
        $merchant = $this->get(MerchantInfo::class)->decode($this->stored(Config::PATH_MERCHANT));
        $this->assertNotNull($merchant);
        $this->assertSame('Test Coffee', $merchant['businessName']);
        $this->assertSame(12, $merchant['onchainAddresses']);
    }

    public function testAKeyTheSdkRefusesIsNotSavedAndItsMessageNeverHoldsIt(): void
    {
        $messages = [
            [new InvalidKeyException(), 'This is not the secret key of a sattle.me integration'],
            [new RevokedException(), 'This integration was removed in the sattle.me app'],
            [new ChannelNotFoundException(true), 'This integration needs a newer version of sattle.me for Magento'],
            [new ChannelNotFoundException(false), 'This integration could not be found on its relays'],
            [new NetworkException(), 'sattle.me could not be reached to check this key'],
        ];
        foreach ($messages as [$error, $message]) {
            $this->sdk()->merchants = [$error];
            try {
                $this->saveKey(Sdk::KEY);
                $this->fail('Saved');
            } catch (LocalizedException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertStringNotContainsString(Sdk::KEY, $e->getMessage());
                $this->assertStringNotContainsString('sattleme_ck_qE', $e->getMessage());
            }
            $this->assertNull($this->stored(Config::PATH_KEY));
        }
    }

    public function testTheKeyShownMaskedIsKeptWhenTheFormIsSavedAgain(): void
    {
        $this->saveKey(Sdk::KEY);
        $stored = $this->stored(Config::PATH_KEY);

        $this->saveKey('******');

        $this->assertSame($stored, $this->stored(Config::PATH_KEY));
        $this->assertSame(1, $this->sdk()->merchantCalls, 'Not checked again');
    }

    public function testTheKeyIsNeverLogged(): void
    {
        // The module's log handler writes under Magento's root (BP), as Magento's own handlers do.
        $root = $this->get(\Magento\Framework\Filesystem\DirectoryList::class)->getRoot();
        $log = $root . '/var/log/sattleme_payment.log';
        $this->sdk()->merchants = [new InvalidInvoiceException(), new \RuntimeException(Sdk::KEY)];
        foreach ([1, 2] as $try) {
            try {
                $this->saveKey(Sdk::KEY);
            } catch (LocalizedException) {
                // Logged, without the key.
            }
        }

        $this->assertFileExists($log);
        $this->assertStringNotContainsString('sattleme_ck_', (string) file_get_contents($log));
    }

    public function testTheTimeToPayIsOneToSixtyMinutes(): void
    {
        foreach (['0', '61', 'ten', '1.5'] as $minutes) {
            try {
                $this->saveSettings(['minutes' => ['value' => $minutes]]);
                $this->fail('Saved ' . $minutes);
            } catch (LocalizedException $e) {
                $this->assertSame('The time to pay is a number of minutes from 1 to 60.', $e->getMessage());
            }
        }
        $this->saveSettings(['minutes' => ['value' => '30']]);
        $this->assertSame('30', $this->stored(Config::PATH_MINUTES));
    }

    /**
     * @magentoAppArea frontend
     */
    public function testTheCheckoutGetsTheTitleAndDescriptionNeverTheKey(): void
    {
        $method = $this->get(PaymentHelper::class)->getMethodInstance(Config::CODE);
        $this->assertSame('Bitcoin', $method->getTitle());
        $this->setConfig(Config::PATH_TITLE, 'Bitcoin ⚡');
        $this->setConfig(Config::PATH_DESCRIPTION, 'Pay from any wallet.');
        $method = $this->make(PaymentHelper::class)->getMethodInstance(Config::CODE);
        $this->assertSame('Bitcoin ⚡', $method->getTitle());

        $this->get(CheckoutSession::class)->replaceQuote($this->cart());
        $config = $this->get(CompositeConfigProvider::class)->getConfig();

        $this->assertIsArray($config['payment'] ?? null);
        $method = $config['payment'][Config::CODE] ?? null;
        $this->assertIsArray($method);
        $this->assertSame('Pay from any wallet.', $method['description'] ?? null);
        $this->assertIsString($method['iconUrl'] ?? null);
        $this->assertStringContainsString('Sattleme_Payment/images/sattleme-icon.svg', $method['iconUrl']);
        $this->assertStringNotContainsString('sattleme_ck_', (string) json_encode($config));
    }

    /**
     * Saves $key as the configuration page does, in the default scope.
     *
     * @param string $key The key.
     * @return void
     */
    private function saveKey(string $key): void
    {
        $this->saveSettings(['integration_key' => ['value' => $key]]);
    }

    /**
     * Saves $fields of the method's group as the configuration page does, in the default scope.
     *
     * @param array<string, array{value: string}> $fields The fields.
     * @return void
     */
    private function saveSettings(array $fields): void
    {
        /** @var ConfigModel $config */
        $config = $this->get(ConfigFactory::class)->create([
            'data' => [
                'section' => 'payment',
                'website' => null,
                'store' => null,
                'groups' => ['sattleme' => ['fields' => $fields]],
            ],
        ]);
        $config->save();
    }

    /**
     * The value stored for $path in the default scope, as the database holds it.
     *
     * @param string $path The path.
     * @return string|null
     */
    private function stored(string $path): ?string
    {
        $resource = $this->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $values = $connection->fetchCol(
            $connection->select()
                ->from($resource->getTableName('core_config_data'), 'value')
                ->where('path = ?', $path)
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
        );

        $value = $values[0] ?? null;

        return is_string($value) ? $value : null;
    }
}
