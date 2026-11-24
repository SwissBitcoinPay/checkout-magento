<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Sattleme\Payment\Model\ClockInterface;
use Sattleme\Payment\Model\KeyVerifier;
use Sattleme\Payment\Model\MerchantInfo;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Merchant;

/**
 * The integration key's setting: write-only (the field shows "******" once a key is saved, and
 * sends it back as such, which keeps the key), encrypted in the database with Magento's key
 * (Encrypted), and checked before it is saved (KeyVerifier): a key refused is not saved, nor sent
 * back to the browser.
 */
class IntegrationKey extends Encrypted
{
    /**
     * @var Merchant|null
     */
    private ?Merchant $verified = null;

    /**
     * @param Context $context The model's context.
     * @param Registry $registry Magento's registry.
     * @param ScopeConfigInterface $config Magento's configuration.
     * @param TypeListInterface $cacheTypeList Magento's caches.
     * @param EncryptorInterface $encryptor Magento's encryption.
     * @param KeyVerifier $verifier The key's check.
     * @param MerchantInfo $merchantInfo The merchant found.
     * @param ClockInterface $clock The clock.
     * @param AbstractResource|null $resource The resource.
     * @param AbstractDb|null $resourceCollection The collection.
     * @param array<string,mixed> $data The data.
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        private readonly KeyVerifier $verifier,
        private readonly MerchantInfo $merchantInfo,
        private readonly ClockInterface $clock,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $encryptor,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Checks a new key, then encrypts it.
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException When the key is refused.
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        $value = is_string($value) ? trim($value) : '';
        $this->verified = null;
        if ($value !== '' && preg_match('/^\*+$/D', $value) !== 1) {
            $this->verified = $this->verifier->verify($value);
            $this->setValue($value);
        }

        parent::beforeSave();

        return $this;
    }

    /**
     * Keeps what the check found of the merchant (or forgets it with the key).
     *
     * @return $this
     */
    public function afterSave()
    {
        $scope = $this->getScope();
        $scope = is_string($scope) && $scope !== '' ? $scope : 'default';
        $scopeId = $this->getScopeId();
        $scopeId = is_numeric($scopeId) ? (int) $scopeId : 0;
        if ($this->verified !== null) {
            $this->merchantInfo->save($scope, $scopeId, $this->verified, $this->clock->now());
        } elseif ($this->getValue() === '' || $this->getValue() === null) {
            $this->merchantInfo->delete($scope, $scopeId);
        }

        return parent::afterSave();
    }

    /**
     * Never kept by a serialization: the merchant found is for this save only.
     *
     * @return string[]
     */
    public function __sleep()
    {
        return array_diff(parent::__sleep(), ['verified', 'verifier', 'merchantInfo', 'clock']);
    }
}
