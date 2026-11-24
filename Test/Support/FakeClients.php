<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Support;

use Sattleme\Payment\Sdk\CheckoutClientInterface;
use Sattleme\Payment\Sdk\ClientProviderInterface;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;

/**
 * The SDK's clients, faked: one client for every key but "" (refused, as the SDK refuses what is
 * not a key), and the keys asked for.
 */
class FakeClients implements ClientProviderInterface
{
    /**
     * The client every key gets.
     *
     * @var FakeCheckoutClient
     */
    public FakeCheckoutClient $client;

    /**
     * The keys clients were asked for.
     *
     * @var list<string>
     */
    public array $keys = [];

    /**
     * Fakes, at the time of $clock.
     *
     * @param FakeClock $clock The clock.
     */
    public function __construct(FakeClock $clock)
    {
        $this->client = new FakeCheckoutClient($clock);
    }

    /**
     * Forgets the keys asked for, and the client's answers and calls.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->keys = [];
        $this->client->reset();
    }

    /**
     * @inheritDoc
     */
    public function forKey(#[\SensitiveParameter] string $key): CheckoutClientInterface
    {
        $this->keys[] = $key;
        if ($key === '' || !str_starts_with($key, 'sattleme_ck_')) {
            throw new InvalidKeyException();
        }

        return $this->client;
    }
}
