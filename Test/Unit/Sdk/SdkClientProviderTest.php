<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Sattleme\Payment\Sdk\SdkCheckoutClient;
use Sattleme\Payment\Sdk\SdkClientProvider;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Client;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;

/**
 * The real SDK's client, made by the module, without the network.
 */
class SdkClientProviderTest extends TestCase
{
    public function testTheSdkMakesAClientOfAKeyAndKeepsItOutOfItsDumps(): void
    {
        $client = (new SdkClientProvider($this->logger()))->forKey(Sdk::KEY);
        $this->assertInstanceOf(SdkCheckoutClient::class, $client);
        $dump = print_r($client, true) . var_export($client, true);
        $this->assertStringNotContainsString(Sdk::KEY, $dump);
        $this->assertStringNotContainsString('qEVejHuCZpF4XMmBSw5p7iZYxCtm20XxzT1fnJ9VB8o', $dump);
    }

    public function testTheSdkIsTheCopyThePackageEmbedsAndNoOther(): void
    {
        // The module installed as Composer installs its package: vendor/sattleme/module-payment.
        $file = (string) (new \ReflectionClass(Client::class))->getFileName();

        $this->assertStringEndsWith(
            '/sattleme/module-payment/vendor/sattleme/checkout/src/Client.php',
            str_replace('\\', '/', $file)
        );
        // Nothing installs it unprefixed beside it.
        $this->assertFalse(class_exists('Sattleme\\Checkout\\Client'));
    }

    public function testTheSdkRefusesWhatIsNotAKeyWithoutSayingIt(): void
    {
        $provider = new SdkClientProvider($this->logger());
        $keys = [
            '', 'sattleme_ck_', 'sattleme_ck_not.a.key', 'xyz_ck_' . substr(Sdk::KEY, 12), substr(Sdk::KEY, 0, -4),
        ];
        foreach ($keys as $key) {
            try {
                $provider->forKey($key);
                $this->fail('A key was taken: ' . $key);
            } catch (InvalidKeyException $e) {
                $this->assertSame('invalid_key', $e->getErrorCode());
                $this->assertFalse($e->isRetryable());
                if ($key !== '') {
                    $this->assertStringNotContainsString($key, $e->getMessage());
                    $this->assertStringNotContainsString($key, $e->getTraceAsString());
                }
            }
        }
    }

    public function testTheClientHasTheModulesTimeoutsAndLog(): void
    {
        $logger = $this->logger();
        $options = (new SdkClientProvider($logger))->options(Sdk::KEY);
        $this->assertSame(SdkClientProvider::RELAY_TIMEOUT_MS, $options['relayTimeoutMs']);
        $this->assertSame(SdkClientProvider::HTTP_TIMEOUT_MS, $options['httpTimeoutMs']);
        $this->assertInstanceOf(\Closure::class, $options['logger']);
        ($options['logger'])('a relay did not answer');
        $this->assertSame(['warning: SDK: a relay did not answer'], $logger->lines);
    }

    /**
     * A logger that keeps what it is given.
     *
     * @return AbstractLogger&object{lines: list<string>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /**
             * @var list<string>
             */
            public array $lines = [];

            /**
             * @inheritDoc
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = (is_string($level) ? $level : 'level') . ': ' . $message;
            }
        };
    }
}
