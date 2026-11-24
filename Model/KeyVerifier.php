<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Sattleme\Payment\Sdk\ClientProviderInterface;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\Payment\Vendor\Sattleme\Checkout\TimeoutCancellation;

/**
 * Checks an integration key as the merchant saves it: the SDK refuses what is not a key, then
 * reads the integration on the merchant's relays (merchant()), so that the key of an integration
 * removed in the app (revoked), or of one the relays do not know, is refused. Its messages never
 * hold the key.
 */
class KeyVerifier
{
    /**
     * The check, as a whole, in seconds.
     */
    public const TIMEOUT = 15;

    /**
     * Checks keys.
     *
     * @param ClientProviderInterface $clients The SDK's clients.
     * @param Errors $errors The SDK's errors.
     * @param LoggerInterface $logger The module's log.
     */
    public function __construct(
        private readonly ClientProviderInterface $clients,
        private readonly Errors $errors,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The merchant $key is the integration of.
     *
     * @param string $key The key.
     * @return Merchant
     * @throws LocalizedException With why it is refused, in the merchant's words.
     */
    public function verify(#[\SensitiveParameter] string $key): Merchant
    {
        try {
            return $this->clients->forKey($key)->merchant(new TimeoutCancellation(self::TIMEOUT));
        } catch (InvalidKeyException) {
            $message = __(
                'This is not the secret key of a sattle.me integration: '
                . 'copy the whole key from the app, sattleme_ck_ included.'
            );
        } catch (RevokedException) {
            $message = __(
                'This integration was removed in the sattle.me app: create a new integration, and enter its key.'
            );
        } catch (ChannelNotFoundException $e) {
            $message = $e->isNewerFormat()
                ? __('This integration needs a newer version of sattle.me for Magento: update the module.')
                : __(
                    'This integration could not be found on its relays: '
                    . 'open the sattle.me app, check the key, and try again.'
                );
        } catch (NetworkException | CancelledException) {
            $message = __(
                'sattle.me could not be reached to check this key: '
                . 'check the server\'s outbound connections, and try again.'
            );
        } catch (UnsupportedEnvironmentException $e) {
            $message = __('The server\'s PHP lacks what sattle.me needs: %1', $e->getMessage());
        } catch (SattlemeException $e) {
            $this->logger->warning('The key could not be checked: ' . $this->errors->describe($e));
            $message = __('This key could not be checked (%1). Try again.', $e->getErrorCode());
        } catch (\Throwable $e) {
            $this->logger->error('The key could not be checked: ' . $this->errors->describe($e));
            $message = __('This key could not be checked. Try again.');
        }

        throw new LocalizedException($message);
    }
}
