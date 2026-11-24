<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\CheckoutUnavailableException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidOptionException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\RateException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

class ErrorsTest extends TestCase
{
    public function testEachErrorOfTheSdkHasItsCodeAndRetryability(): void
    {
        $errors = new Errors();
        $invoice = Sdk::declared(Sdk::invoice([], 1_800_000_000), 1_800_000_000);
        $cases = [
            ['invalid_key', false, new InvalidKeyException()],
            ['invalid_option', false, new InvalidOptionException('options')],
            ['invalid_order', false, new InvalidOrderException('sats')],
            ['revoked', false, new RevokedException()],
            ['channel_not_found', true, new ChannelNotFoundException()],
            ['channel_not_found', false, new ChannelNotFoundException(true)],
            ['network', true, new NetworkException()],
            ['invalid_invoice', false, new InvalidInvoiceException()],
            ['invoice_pending', false, new InvoicePendingException($invoice, null)],
            ['order_paid', false, new OrderPaidException($invoice, Sdk::paidLightning())],
            ['unsupported_currency', false, new UnsupportedCurrencyException('XXX')],
            ['rate', true, new RateException()],
            ['checkout_unavailable', false, new CheckoutUnavailableException()],
            ['onchain_unavailable', true, new OnchainUnavailableException()],
        ];
        foreach ($cases as [$code, $transient, $error]) {
            $this->assertSame($code, $errors->code($error), get_class($error));
            $this->assertSame($transient, $errors->isTransient($error), get_class($error));
            $this->assertStringStartsWith($code . ': ', $errors->describe($error));
        }
        $this->assertTrue($errors->isOrderError(new InvalidOrderException('fiat')));
        $this->assertTrue($errors->isOrderError(new UnsupportedCurrencyException('XXX')));
        $this->assertFalse($errors->isOrderError(new NetworkException()));
    }

    public function testCancellationsAndTheEnvironmentHaveCodesOfTheirOwn(): void
    {
        $errors = new Errors();
        $this->assertSame(Errors::TIMEOUT, $errors->code(new CancelledException()));
        $this->assertTrue($errors->isTransient(new CancelledException()));
        $environment = new UnsupportedEnvironmentException('sattleme/checkout needs gmp');
        $this->assertSame(Errors::ENVIRONMENT, $errors->code($environment));
        $this->assertSame('environment: sattleme/checkout needs gmp', $errors->describe($environment));
        $this->assertFalse($errors->isTransient(new UnsupportedEnvironmentException('x')));
    }

    public function testAnUnexpectedErrorIsDescribedWithoutItsMessage(): void
    {
        $errors = new Errors();
        $secret = 'sattleme_ck_' . str_repeat('s', 40);
        $error = new \RuntimeException('Failed with ' . $secret);
        $this->assertSame(Errors::UNEXPECTED, $errors->code($error));
        $described = $errors->describe($error);
        $this->assertStringNotContainsString($secret, $described);
        $this->assertStringStartsWith('error: RuntimeException at ErrorsTest.php:', $described);
        $this->assertFalse($errors->isTransient($error));
    }

    public function testEveryCodeTheModuleStoresIsExplainedToTheMerchant(): void
    {
        $errors = new Errors();
        $this->assertNull($errors->explain(''));
        $codes = [
            'network', Errors::TIMEOUT, 'rate', 'onchain_unavailable', 'invalid_key', 'revoked', 'channel_not_found',
            'invalid_invoice', 'invalid_order', 'unsupported_currency', Errors::ENVIRONMENT, Errors::APPLY,
            Errors::DEADLINE, Errors::MISMATCH, Errors::LOCK, Errors::NO_KEY, Errors::PRICE, Errors::ABANDONED,
        ];
        foreach ($codes as $code) {
            $explained = (string) $errors->explain($code);
            $this->assertNotSame('', $explained, $code);
            $this->assertStringNotContainsString('Error: ', $explained, $code);
        }
        $this->assertSame('Error: something_new.', (string) $errors->explain('something_new'));
    }
}
