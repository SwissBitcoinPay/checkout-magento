<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Sattleme\Payment\Sdk\ClientProviderInterface;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Sdk\Prices;

/**
 * Whether a cart can be paid with bitcoin.
 *
 * An integration key is set (and the SDK takes it, on this server's PHP), the cart's currency (the
 * one the customer sees and pays in) is one the SDK converts, and its total a price the SDK takes
 * (no more decimals than the currency has). Magento's own checks apply as well: enabled,
 * countries, minimum and maximum totals.
 */
class Availability
{
    /**
     * Whether each store's key was usable, for this process.
     *
     * @var array<int,bool>
     */
    private array $usable = [];

    /**
     * Tells which carts can be paid.
     *
     * @param Config $config The settings.
     * @param ClientProviderInterface $clients The SDK's clients.
     * @param Prices $prices The prices the SDK takes.
     * @param Errors $errors The SDK's errors.
     * @param LoggerInterface $logger The module's log.
     */
    public function __construct(
        private readonly Config $config,
        private readonly ClientProviderInterface $clients,
        private readonly Prices $prices,
        private readonly Errors $errors,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether $quote can be paid with this method.
     *
     * @param CartInterface $quote The cart.
     * @return bool
     */
    public function isAvailable(CartInterface $quote): bool
    {
        if (!$this->isUsable($quote->getStoreId())) {
            return false;
        }
        $currency = (string) $quote->getCurrency()?->getQuoteCurrencyCode();
        if (!$this->prices->isSupportedCurrency($currency)) {
            return false;
        }
        $total = $this->prices->decimal($quote instanceof Quote ? $quote->getGrandTotal() : null);

        return $total !== null && $this->prices->amount($total, $currency) !== null;
    }

    /**
     * Whether store $storeId has a key the SDK takes, on this server.
     *
     * @param int $storeId The store.
     * @return bool
     */
    public function isUsable(int $storeId): bool
    {
        if (!isset($this->usable[$storeId])) {
            $key = $this->config->integrationKey($storeId);
            $usable = false;
            if ($key !== '') {
                try {
                    $this->clients->forKey($key);
                    $usable = true;
                } catch (\Throwable $e) {
                    $this->logger->warning('Bitcoin is not offered: ' . $this->errors->describe($e));
                }
            }
            $this->usable[$storeId] = $usable;
        }

        return $this->usable[$storeId];
    }
}
