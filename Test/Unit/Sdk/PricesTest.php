<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Sdk\Prices;
use Sattleme\Payment\Tools\Package;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\Pricing;

class PricesTest extends TestCase
{
    public function testTheModuleUsesNoInternalOfTheSdk(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/tools/build.php';
        $users = [];
        // The module's directories, as its package holds them.
        $dirs = array_filter(Package::CONTENTS, static fn(string $entry): bool => str_ends_with($entry, '/'));
        foreach ($dirs as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), ['php', 'phtml'], true)) {
                    continue;
                }
                foreach (\PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
                    $kinds = [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING];
                    $internal = str_contains($token->text, 'Sattleme\\Checkout\\Internal');
                    if ($internal && in_array($token->id, $kinds, true)) {
                        $users[] = substr($file->getPathname(), strlen($root) + 1);
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($users)));
    }

    public function testTheSdkConvertsTheCurrenciesOfItsTable(): void
    {
        $prices = new Prices();
        foreach (['CHF' => 2, 'EUR' => 2, 'USD' => 2, 'JPY' => 0, 'KWD' => 3] as $currency => $digits) {
            $this->assertTrue($prices->isSupportedCurrency($currency), $currency);
            $this->assertSame($digits, $prices->digits($currency), $currency);
        }
        foreach (['XXX', 'BTC', 'chf', 'CH', 'CHFF', '', 'XAU', ' CHF', 'CHF ', "CHF\0", '756'] as $currency) {
            $this->assertFalse($prices->isSupportedCurrency($currency), $currency);
            $this->assertNull($prices->digits($currency), $currency);
        }
    }

    public function testATotalIsThePriceTheSdkTakesExactly(): void
    {
        $prices = new Prices();
        $this->assertSame('24.5', $prices->amount('24.5000', 'CHF'));
        $this->assertSame('24.55', $prices->amount('24.5500', 'CHF'));
        $this->assertSame('100', $prices->amount('100.0000', 'JPY'));
        $this->assertSame('1.234', $prices->amount('1.2340', 'KWD'));
        $this->assertSame('0.01', $prices->amount('0.0100', 'USD'));
        $this->assertSame('7', $prices->amount('007', 'EUR'));
        $this->assertSame('90071992547409.91', $prices->amount('90071992547409.9100', 'CHF'), '2^53 - 1 cents');
        $this->assertSame('9007199254740.991', $prices->amount('9007199254740.9910', 'KWD'));
    }

    public function testATotalThatCannotBePaidExactlyIsNeverRounded(): void
    {
        $prices = new Prices();
        $this->assertNull($prices->amount('24.555', 'CHF'));
        $this->assertNull($prices->amount('100.5', 'JPY'));
        $this->assertNull($prices->amount('0.0000', 'CHF'));
        $this->assertNull($prices->amount('-1', 'CHF'));
        $this->assertNull($prices->amount('1e3', 'CHF'));
        $this->assertNull($prices->amount('12,50', 'CHF'));
        $this->assertNull($prices->amount('24.50', 'XXX'));
        $this->assertNull($prices->amount('', 'CHF'));
        $this->assertNull($prices->amount('90071992547409.9200', 'CHF'), '2^53 cents');
        $this->assertNull($prices->amount('9007199254740.9920', 'KWD'));
        $this->assertNull($prices->amount('999999999999999.9900', 'CHF'));
    }

    /**
     * The SDK's own vectors of prices in a currency (sdk/spec/vectors/fiat.json, SPEC.md §25):
     * the module offers and takes as a price exactly what the SDK takes, the embedded SDK (its
     * check of an order's price, which createInvoice runs) reading each the same.
     */
    public function testItTakesAndRefusesThePricesTheSdkDoes(): void
    {
        $vectors = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/sdk/spec/vectors/fiat.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($vectors);
        $this->assertIsArray($vectors['digits'] ?? null);
        $this->assertIsArray($vectors['cases'] ?? null);
        $prices = new Prices();
        foreach ($vectors['digits'] as $case) {
            $this->assertIsArray($case);
            $this->assertIsString($case['code'] ?? null);
            $this->assertSame($case['expected'] ?? null, $prices->digits($case['code']), $case['code']);
            $this->assertSame(($case['expected'] ?? null) !== null, $prices->isSupportedCurrency($case['code']));
        }
        $this->assertGreaterThan(20, count($vectors['cases']));
        foreach ($vectors['cases'] as $case) {
            $this->assertIsArray($case);
            $input = $case['input'] ?? null;
            $expected = $case['expected'] ?? null;
            $this->assertIsArray($input);
            $this->assertIsArray($expected);
            $this->assertIsString($input['amount'] ?? null);
            $this->assertIsString($input['currency'] ?? null);
            $name = json_encode($input, JSON_THROW_ON_ERROR);
            try {
                $sdk = Pricing::fiat($input)->amount;
            } catch (InvalidOrderException) {
                $sdk = null;
            }
            $amount = $prices->amount($input['amount'], $input['currency']);
            if (isset($expected['error'])) {
                $this->assertNull($sdk, $name);
                $this->assertNull($amount, $name);
                continue;
            }
            $this->assertIsString($expected['amount'] ?? null);
            $this->assertIsInt($expected['digits'] ?? null);
            $this->assertSame($expected['amount'], $sdk, $name);
            $this->assertIsNumeric($amount, $name);
            $this->assertSame($expected['amount'], bcadd($amount, '0', $expected['digits']), $name);
            $this->assertSame(
                $expected['amount'],
                Pricing::fiat(['amount' => $amount, 'currency' => $input['currency']])->amount,
                $name
            );
        }
    }

    public function testMagentosAmountsAreReadWithoutTheFloatsNoise(): void
    {
        $prices = new Prices();
        $this->assertSame('24.5000', $prices->decimal(24.5));
        $this->assertSame('0.3000', $prices->decimal(0.1 + 0.2));
        $this->assertSame('10.0000', $prices->decimal(10));
        $this->assertSame('24.5000', $prices->decimal('24.5000'));
        $this->assertNull($prices->decimal(-1.0));
        $this->assertNull($prices->decimal(INF));
        $this->assertNull($prices->decimal(NAN));
        $this->assertNull($prices->decimal('abc'));
        $this->assertNull($prices->decimal(null));
    }

    public function testAnInvoicePaysAnOrderOnlyAtItsPrice(): void
    {
        $prices = new Prices();
        $fiat = ['amount' => '24.50', 'currency' => 'CHF', 'minor' => '2450', 'digits' => 2];
        $this->assertTrue($prices->isPriceOf($fiat, '24.5000', 'CHF'));
        $this->assertFalse($prices->isPriceOf($fiat, '24.6000', 'CHF'));
        $this->assertFalse($prices->isPriceOf($fiat, '24.5000', 'EUR'));
        $this->assertFalse($prices->isPriceOf(null, '24.5000', 'CHF'));
        $this->assertFalse($prices->isPriceOf(['amount' => 24.5, 'currency' => 'CHF'], '24.5000', 'CHF'));
        $this->assertFalse($prices->isPriceOf(['amount' => '24.50'], '24.5000', 'CHF'));
    }

    public function testAnAmountIsShownWithItsCurrencysDecimals(): void
    {
        $prices = new Prices();
        $this->assertSame('35.50 USD', $prices->display('35.5000', 'USD'));
        $this->assertSame('0.00 CHF', $prices->display('0', 'CHF'));
        $this->assertSame('1234 JPY', $prices->display('1234.0000', 'JPY'));
        $this->assertSame('1.234 KWD', $prices->display('1.2340', 'KWD'));
        $this->assertSame('24.555 CHF', $prices->display('24.555', 'CHF'), 'As it is, never rounded');
        $this->assertSame('12 XXX', $prices->display('12', 'XXX'));
    }
}
