<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Store\Model\StoreManagerInterface;
use Sattleme\Payment\Model\Config;

/**
 * What the checkout's script knows of the method (window.checkoutConfig.payment.sattleme): its
 * description and its icon. Never the key.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @param Config $config The settings.
     * @param StoreManagerInterface $stores The stores.
     * @param Repository $assets Magento's static files.
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
        private readonly Repository $assets
    ) {
    }

    /**
     * The method's part of the checkout's configuration.
     *
     * @return array<string,mixed>
     */
    public function getConfig()
    {
        $storeId = $this->stores->getStore()->getId();
        $description = $this->config->description($storeId);

        return [
            'payment' => [
                Config::CODE => [
                    'description' => $description !== ''
                        ? $description
                        : (string) __(
                            'Pay in bitcoin, in Lightning or on-chain, from your wallet: '
                            . 'once your order is placed, you see its invoice as a QR code.'
                        ),
                    'iconUrl' => $this->assets->getUrlWithParams(
                        'Sattleme_Payment::images/sattleme-icon.svg',
                        ['_secure' => true]
                    ),
                ],
            ],
        ];
    }
}
