<?php

declare(strict_types=1);

namespace Sattleme\Payment\Sdk;

use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

/**
 * Gives the SDK's clients of an integration key.
 *
 * A client keeps nothing between calls but its options: one per call costs little, and a key
 * changed in the settings is used at once.
 *
 * @api
 */
interface ClientProviderInterface
{
    /**
     * A client of $key.
     *
     * @param string $key The integration key (a secret: never logged, never shown).
     * @return CheckoutClientInterface
     * @throws InvalidKeyException When it is not an integration key.
     * @throws UnsupportedEnvironmentException When PHP lacks what the SDK needs.
     */
    public function forKey(#[\SensitiveParameter] string $key): CheckoutClientInterface;
}
