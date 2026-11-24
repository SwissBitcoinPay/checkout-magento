<?php

declare(strict_types=1);

namespace Sattleme\Payment\Sdk;

use Psr\Log\LoggerInterface;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Client;

/**
 * The SDK's clients, with the module's timeouts and its log.
 */
class SdkClientProvider implements ClientProviderInterface
{
    /**
     * Per relay request, connection included, in milliseconds.
     */
    public const RELAY_TIMEOUT_MS = 5000;

    /**
     * Per HTTP request, body included, in milliseconds.
     */
    public const HTTP_TIMEOUT_MS = 8000;

    /**
     * Makes clients that log to $logger.
     *
     * @param LoggerInterface $logger The module's log (the SDK's warnings never hold a secret).
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @inheritDoc
     */
    public function forKey(#[\SensitiveParameter] string $key): CheckoutClientInterface
    {
        return new SdkCheckoutClient(new Client($this->options($key)));
    }

    /**
     * The client's options: the key, the module's timeouts, its log.
     *
     * @param string $key The integration key.
     * @return array<string,mixed>
     */
    public function options(#[\SensitiveParameter] string $key): array
    {
        $logger = $this->logger;

        return [
            'key' => $key,
            'relayTimeoutMs' => self::RELAY_TIMEOUT_MS,
            'httpTimeoutMs' => self::HTTP_TIMEOUT_MS,
            'logger' => function (string $message) use ($logger): void {
                $logger->warning('SDK: ' . $message);
            },
        ];
    }
}
