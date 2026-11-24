<?php

declare(strict_types=1);

namespace Sattleme\PaymentE2e\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Sattleme\Payment\Sdk\CheckoutClientInterface;
use Sattleme\Payment\Sdk\ClientProviderInterface;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;

/**
 * The SDK's clients, faked for the end-to-end test: a key of the right prefix gets the fake.
 */
class FakeClients implements ClientProviderInterface
{
    /**
     * @param DirectoryList $directories Magento's directories (the fake keeps its state in var/).
     */
    public function __construct(private readonly DirectoryList $directories)
    {
    }

    /**
     * @inheritDoc
     */
    public function forKey(#[\SensitiveParameter] string $key): CheckoutClientInterface
    {
        if (!str_starts_with($key, 'sattleme_ck_')) {
            throw new InvalidKeyException();
        }

        return new FakeClient($this->directories->getPath(DirectoryList::VAR_DIR) . '/sattleme-e2e');
    }
}
