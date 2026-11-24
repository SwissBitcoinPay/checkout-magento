<?php

declare(strict_types=1);

namespace Sattleme\Payment\Gateway\Config;

use Magento\Payment\Gateway\Config\ValueHandlerInterface;
use Sattleme\Payment\Model\Config;

/**
 * The method's title: the merchant's, set per store view (its own words, in each store's
 * language), else "Bitcoin" in the store's language.
 */
class TitleHandler implements ValueHandlerInterface
{
    /**
     * @param Config $config The settings.
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * The title.
     *
     * @param array<mixed> $subject The field and the payment.
     * @param int|null $storeId The store.
     * @return string
     */
    public function handle(array $subject, $storeId = null)
    {
        $title = $this->config->title($storeId);

        return $title !== '' ? $title : (string) __('Bitcoin');
    }
}
