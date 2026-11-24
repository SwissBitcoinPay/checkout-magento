<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The payment method's settings (payment/sattleme/…), per store.
 */
class Config
{
    /**
     * The payment method's code.
     */
    public const CODE = 'sattleme';

    public const PATH_ACTIVE = 'payment/sattleme/active';

    public const PATH_KEY = 'payment/sattleme/integration_key';

    public const PATH_MINUTES = 'payment/sattleme/minutes';

    public const PATH_TITLE = 'payment/sattleme/title';

    public const PATH_DESCRIPTION = 'payment/sattleme/description';

    public const PATH_MERCHANT = 'payment/sattleme/merchant';

    /**
     * Magento's lifetime of an order pending payment, in minutes (Sales → Orders Cron Settings).
     */
    public const PATH_PENDING_LIFETIME = 'sales/orders/delete_pending_after';

    /**
     * Reads the settings.
     *
     * @param ScopeConfigInterface $scopeConfig Magento's configuration.
     * @param Durations $durations The invoices' durations.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Durations $durations
    ) {
    }

    /**
     * Whether the method is enabled in $storeId.
     *
     * @param int|string|null $storeId The store (its id).
     * @return bool
     */
    public function isActive(int|string|null $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The integration key of $storeId (decrypted: config.xml declares its backend model), or "".
     *
     * A secret: never logged, never shown, never sent to the browser.
     *
     * @param int|string|null $storeId The store (its id).
     * @return string
     */
    public function integrationKey(int|string|null $storeId): string
    {
        $key = $this->scopeConfig->getValue(self::PATH_KEY, ScopeInterface::SCOPE_STORE, $storeId);

        return is_string($key) ? trim($key) : '';
    }

    /**
     * The time to pay an invoice, in minutes (1 to 60, 15 by default).
     *
     * @param int|string|null $storeId The store (its id).
     * @return int
     */
    public function minutes(int|string|null $storeId): int
    {
        return $this->durations->minutes(
            $this->scopeConfig->getValue(self::PATH_MINUTES, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }

    /**
     * The title the customer reads at checkout, as the merchant set it for the store, else "".
     *
     * The merchant's own words, in each store view's language; else the module's, translated.
     *
     * @param int|string|null $storeId The store (its id).
     * @return string
     */
    public function title(int|string|null $storeId): string
    {
        $title = $this->scopeConfig->getValue(self::PATH_TITLE, ScopeInterface::SCOPE_STORE, $storeId);

        return is_string($title) ? trim($title) : '';
    }

    /**
     * The description the customer reads at checkout, as the merchant set it, else "".
     *
     * @param int|string|null $storeId The store (its id).
     * @return string
     */
    public function description(int|string|null $storeId): string
    {
        $description = $this->scopeConfig->getValue(self::PATH_DESCRIPTION, ScopeInterface::SCOPE_STORE, $storeId);

        return is_string($description) ? trim($description) : '';
    }

    /**
     * Seconds after its last change at which Magento's cron cancels an order of $storeId still
     * pending payment (Pending Payment Order Lifetime, in minutes), or null when it never does (a
     * value its cron would not use).
     *
     * @param int|string|null $storeId The store (its id).
     * @return int|null
     */
    public function pendingLifetimeSeconds(int|string|null $storeId): ?int
    {
        $value = $this->scopeConfig->getValue(self::PATH_PENDING_LIFETIME, ScopeInterface::SCOPE_STORE, $storeId);
        if (!is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (int) floor((float) $value * 60);
    }
}
