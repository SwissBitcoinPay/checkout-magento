<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sattleme\Payment\Model\KeyVerifier;
use Sattleme\Payment\Sdk\CheckoutClientInterface;
use Sattleme\Payment\Sdk\ClientProviderInterface;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Test\Support\FakeClients;
use Sattleme\Payment\Test\Support\FakeClock;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

class KeyVerifierTest extends TestCase
{
    public function testAKeyIsTakenWhenTheMerchantsRelaysKnowItsIntegration(): void
    {
        $clients = new FakeClients(new FakeClock(1_800_000_000));
        $merchant = (new KeyVerifier($clients, new Errors(), new NullLogger()))->verify(Sdk::KEY);
        $this->assertSame('Test Coffee', $merchant->businessName);
        $this->assertSame([Sdk::KEY], $clients->keys);
        $this->assertSame(1, $clients->client->merchantCalls);
    }

    public function testEachRefusalSaysWhyInTheMerchantsWordsNeverTheKey(): void
    {
        $cases = [
            'not the secret key of a sattle.me integration' => null,
            'was removed in the sattle.me app' => new RevokedException(),
            'could not be found on its relays' => new ChannelNotFoundException(),
            'needs a newer version of sattle.me for Magento' => new ChannelNotFoundException(true),
            'could not be reached to check this key' => new NetworkException(),
            'could not be reached to check this key ' => new CancelledException(),
            'This key could not be checked (invalid_invoice)' => new InvalidInvoiceException(),
            'This key could not be checked. Try again.' => new \RuntimeException('boom ' . Sdk::KEY),
        ];
        foreach ($cases as $expected => $error) {
            $clients = new FakeClients(new FakeClock(1_800_000_000));
            if ($error !== null) {
                $clients->client->merchants = [$error];
            }
            $key = $error === null ? 'not-a-key-' . str_repeat('x', 20) : Sdk::KEY;
            try {
                (new KeyVerifier($clients, new Errors(), new NullLogger()))->verify($key);
                $this->fail('Taken: ' . $expected);
            } catch (LocalizedException $e) {
                $this->assertStringContainsString(trim($expected), $e->getMessage());
                $this->assertStringNotContainsString($key, $e->getMessage());
                $this->assertNull($e->getPrevious(), 'The SDK\'s exception is never chained');
            }
        }
    }

    public function testAServerWithoutWhatTheSdkNeedsIsSaidSo(): void
    {
        $clients = $this->createStub(ClientProviderInterface::class);
        $clients->method('forKey')->willThrowException(
            new UnsupportedEnvironmentException('sattleme/checkout needs the bcmath extension')
        );
        try {
            (new KeyVerifier($clients, new Errors(), new NullLogger()))->verify(Sdk::KEY);
            $this->fail('Taken');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('needs the bcmath extension', $e->getMessage());
        }
        $this->assertInstanceOf(CheckoutClientInterface::class, (new FakeClients(new FakeClock()))->forKey(Sdk::KEY));
    }
}
