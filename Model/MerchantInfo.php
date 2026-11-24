<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Merchant;

/**
 * What the key's check found of the merchant (its business's name, the amounts its Lightning
 * address takes, its deposit addresses), kept beside the key for the settings to show: never the
 * key, nothing secret.
 */
class MerchantInfo
{
    /**
     * @param WriterInterface $writer Magento's configuration writer.
     */
    public function __construct(private readonly WriterInterface $writer)
    {
    }

    /**
     * Keeps $merchant for the scope of a key.
     *
     * @param string $scope The scope (default, websites, stores).
     * @param int $scopeId The scope's id.
     * @param Merchant $merchant The merchant.
     * @param int $now The time, in Unix seconds.
     * @return void
     */
    public function save(string $scope, int $scopeId, Merchant $merchant, int $now): void
    {
        $this->writer->save(
            Config::PATH_MERCHANT,
            json_encode(
                [
                    'businessName' => $merchant->businessName,
                    'minSats' => $merchant->minSats,
                    'maxSats' => $merchant->maxSats,
                    'onchainAddresses' => $merchant->onchainAddresses,
                    'checkedAt' => $now,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            $scope,
            $scopeId
        );
    }

    /**
     * Forgets it for the scope of a key removed.
     *
     * @param string $scope The scope.
     * @param int $scopeId The scope's id.
     * @return void
     */
    public function delete(string $scope, int $scopeId): void
    {
        $this->writer->delete(Config::PATH_MERCHANT, $scope, $scopeId);
    }

    /**
     * The merchant as kept (JSON), or null.
     *
     * @param mixed $value The configuration's value.
     * @return array{businessName: string, minSats: string, maxSats: string, onchainAddresses: int, checkedAt: int}|null
     */
    public function decode(mixed $value): ?array
    {
        $data = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($data)
            || !is_string($data['businessName'] ?? null)
            || !is_string($data['minSats'] ?? null)
            || !is_string($data['maxSats'] ?? null)
            || !is_int($data['onchainAddresses'] ?? null)
            || !is_int($data['checkedAt'] ?? null)
        ) {
            return null;
        }

        return [
            'businessName' => $data['businessName'],
            'minSats' => $data['minSats'],
            'maxSats' => $data['maxSats'],
            'onchainAddresses' => $data['onchainAddresses'],
            'checkedAt' => $data['checkedAt'],
        ];
    }
}
