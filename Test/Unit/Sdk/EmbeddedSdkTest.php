<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Client;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\Crypto\Nip44Exception;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\Crypto\Schnorr;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\Pricing;

/**
 * The SDK as the package holds it (prefixed, patched for Adobe's code sniffer), against the SDK's
 * own vectors that need no network, pinned in sdk/ with its files (tools/sdk.php): prices in a
 * currency (SPEC.md §25), integration keys (§8), BIP-340 signatures, NIP-44 encryption; as the
 * SDK's own tests read them.
 */
class EmbeddedSdkTest extends TestCase
{
    /**
     * The SHA-256 of the BIP-340 vectors, as the SDK's own test checks it.
     */
    private const BIP340_SHA256 = '34c9d1d9c3a88d524bc80778540dc43f8306ec249a7485293063c376db851c2d';

    public function testItIsTheCopyThePackageEmbeds(): void
    {
        $file = (string) (new \ReflectionClass(Schnorr::class))->getFileName();

        $this->assertStringEndsWith(
            '/sattleme/module-payment/vendor/sattleme/checkout/src/Internal/Crypto/Schnorr.php',
            str_replace('\\', '/', $file)
        );
    }

    public function testItReadsPricesInACurrencyAsTheSpecificationSays(): void
    {
        $vectors = $this->vectors('spec/vectors/fiat.json');
        foreach ($this->listOf($vectors, 'digits') as $case) {
            $code = $this->text($case['code'] ?? null);
            $this->assertSame(
                $case['expected'] ?? null,
                Client::currencyDigits($code),
                'currencyDigits(' . json_encode($code, JSON_THROW_ON_ERROR) . ')'
            );
        }
        $cases = $this->listOf($vectors, 'cases');
        $this->assertGreaterThan(20, count($cases));
        foreach ($cases as $case) {
            $name = json_encode($case['input'] ?? null, JSON_THROW_ON_ERROR);
            $expected = $case['expected'] ?? null;
            $this->assertIsArray($expected, $name);
            try {
                $result = Pricing::fiat($case['input'] ?? null)->toArray();
            } catch (InvalidOrderException $e) {
                $result = ['error' => $e->getErrorCode(), 'param' => $e->getParam()];
            }
            if (!isset($expected['error'])) {
                $expected = [
                    'amount' => $expected['amount'] ?? null,
                    'currency' => $expected['currency'] ?? null,
                    'minor' => $expected['minor'] ?? null,
                    'digits' => $expected['digits'] ?? null,
                ];
            }
            $this->assertSame($expected, $result, $name);
        }
    }

    public function testItReadsTheIntegrationKeysOfTheSpecification(): void
    {
        $cases = $this->vectors('spec/vectors/integration-keys.json')['cases'] ?? null;
        $this->assertIsArray($cases);
        $this->assertGreaterThan(10, count($cases));
        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $name = $this->text($case['name'] ?? null);
            $expected = $case['expected'] ?? null;
            $this->assertIsArray($expected, $name);
            if (isset($expected['error'])) {
                $this->assertSame('invalid_key', $expected['error'], $name);
                try {
                    IntegrationKey::parse($case['key'] ?? null);
                    $this->fail('An invalid key was taken: ' . $name);
                } catch (InvalidKeyException $e) {
                    $this->assertSame('invalid_key', $e->getErrorCode(), $name);
                    $this->assertFalse($e->isRetryable(), $name);
                }
                continue;
            }
            $key = IntegrationKey::parse($case['key'] ?? null);
            $this->assertSame($expected['teamPublicKey'], $key->team, $name);
            $this->assertSame($expected['integrationKey'], bin2hex($key->key->reveal()), $name);
            $this->assertSame($expected['relays'], $key->relays, $name);
        }
    }

    public function testItSignsAndVerifiesAsBip340Says(): void
    {
        $path = $this->sdk() . '/tests/fixtures/bip340/test-vectors.csv';
        $this->assertSame(self::BIP340_SHA256, hash_file('sha256', $path));
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $header = array_map(strval(...), str_getcsv((string) array_shift($lines), ',', '"', ''));
        $this->assertCount(19, $lines);
        foreach ($lines as $line) {
            $row = array_combine($header, array_map(strval(...), str_getcsv($line, ',', '"', '')));
            $name = 'vector ' . $row['index'];
            $publicKey = $this->bytes($row['public key']);
            $message = $this->bytes($row['message']);
            $signature = $this->bytes($row['signature']);
            if ($row['secret key'] !== '') {
                $secret = $this->bytes($row['secret key']);
                $this->assertSame(bin2hex($publicKey), bin2hex(Secp256k1::xOnlyPublicKey($secret)), $name);
                $this->assertSame(
                    strtolower($row['signature']),
                    bin2hex(Schnorr::sign($secret, $message, $this->bytes($row['aux_rand']))),
                    $name
                );
            }
            $this->assertSame(
                $row['verification result'] === 'TRUE',
                Schnorr::verify($publicKey, $message, $signature),
                $name
            );
        }
    }

    public function testItEncryptsAndDecryptsAsNip44Says(): void
    {
        $vectors = $this->vectors('spec/vectors/nip44/nip44.vectors.json');
        $count = 0;
        foreach ($this->listOf($vectors, 'get_conversation_key') as $case) {
            $this->assertSame(
                $case['conversation_key'],
                bin2hex(Nip44::conversationKey($this->bytes($case['sec1']), $this->bytes($case['pub2'])))
            );
            $count++;
        }
        foreach ($this->listOf($vectors, 'invalid_get_conversation_key') as $case) {
            try {
                Nip44::conversationKey($this->bytes($case['sec1']), $this->bytes($case['pub2']));
                $this->fail('A conversation key of an invalid pair: ' . $this->text($case['note'] ?? null));
            } catch (\InvalidArgumentException) {
                $count++;
            }
        }
        $messageKeys = $vectors['get_message_keys'] ?? null;
        $this->assertIsArray($messageKeys);
        $this->assertIsArray($messageKeys['keys']);
        foreach ($messageKeys['keys'] as $case) {
            $this->assertIsArray($case);
            [$chachaKey, $chachaNonce, $hmacKey] = Nip44::messageKeys(
                $this->bytes($messageKeys['conversation_key']),
                $this->bytes($case['nonce'])
            );
            $this->assertSame($case['chacha_key'], bin2hex($chachaKey));
            $this->assertSame($case['chacha_nonce'], bin2hex($chachaNonce));
            $this->assertSame($case['hmac_key'], bin2hex($hmacKey));
            $count++;
        }
        foreach ($this->listOf($vectors, 'calc_padded_len') as $case) {
            $this->assertIsInt($case[0]);
            $this->assertSame($case[1], Nip44::paddedLength($case[0]), 'calc_padded_len(' . $case[0] . ')');
            $count++;
        }
        foreach ($this->listOf($vectors, 'encrypt_decrypt') as $case) {
            $key = $this->bytes($case['conversation_key']);
            $plaintext = $this->text($case['plaintext']);
            $this->assertSame($case['payload'], Nip44::encrypt($plaintext, $key, $this->bytes($case['nonce'])));
            $this->assertSame($plaintext, Nip44::decrypt($this->text($case['payload']), $key));
            $count++;
        }
        foreach ($this->listOf($vectors, 'invalid_decrypt') as $case) {
            $note = $this->text($case['note'] ?? null);
            try {
                Nip44::decrypt($this->text($case['payload']), $this->bytes($case['conversation_key']));
                $this->fail('A payload that must fail decrypted: ' . $note);
            } catch (Nip44Exception $e) {
                // SPEC.md §12: an empty payload, a "#" first, or another version is a newer format.
                $newer = $case['payload'] === '' || str_starts_with($note, 'unknown encryption version');
                $this->assertSame($newer, $e->newerFormat, $note);
            }
            $count++;
        }

        $this->assertGreaterThan(80, $count);
    }

    /**
     * The pinned copy of the SDK, its vectors with it.
     *
     * @return string
     */
    private function sdk(): string
    {
        return dirname(__DIR__, 3) . '/sdk';
    }

    /**
     * A JSON file of the pinned copy, its objects as arrays.
     *
     * @param string $file The file, relative to sdk/.
     * @return array<mixed>
     */
    private function vectors(string $file): array
    {
        $vectors = json_decode((string) file_get_contents($this->sdk() . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($vectors);

        return $vectors;
    }

    /**
     * The list $name of $vectors, its cases arrays.
     *
     * @param array<mixed> $vectors The vectors.
     * @param string $name The list.
     * @return list<array<mixed>>
     */
    private function listOf(array $vectors, string $name): array
    {
        $list = $vectors[$name] ?? null;
        $this->assertIsArray($list, $name);
        $this->assertNotEmpty($list, $name);
        $cases = [];
        foreach ($list as $case) {
            $this->assertIsArray($case, $name);
            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * A string of the vectors.
     *
     * @param mixed $value The value.
     * @return string
     */
    private function text(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    /**
     * Bytes of a hexadecimal string of the vectors.
     *
     * @param mixed $hex The string.
     * @return string
     */
    private function bytes(mixed $hex): string
    {
        $this->assertIsString($hex);

        return (string) hex2bin($hex);
    }
}
