<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Tools\Embedded;

/**
 * tools/embed.php: the patches of the embedded libraries, the token by token comparison that finds
 * nothing but the prefix and the patches, and the search for unprefixed references.
 */
class EmbeddedTest extends TestCase
{
    /**
     * A file of the SDK as PHP-Scoper prints it, before the prefix (its original, reprinted).
     */
    private const ORIGINAL = <<<'PHP'
<?php

declare (strict_types=1);
namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
final class Environment
{
    public const NAME = 'Sattleme\Checkout\Internal\Environment';
    final public static function check(?string $g): void
    {
        \assert(\is_string($g) && $g !== '');
        if (\extension_loaded('pcre') && @preg_match('/^.$/u', "é") !== 1) {
            throw new UnsupportedEnvironmentException('pcre', true);
        }
        \assert($g !== null);
        echo \strlen($g) > 0 ? 'yes' : 'no';
    }
}

PHP;

    /**
     * That file as PHP-Scoper writes it: prefixed, PHP's constants written with a leading "\".
     */
    private const SCOPED = <<<'PHP'
<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\Sattleme\Checkout\Internal;

use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
final class Environment
{
    public const NAME = 'Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\Environment';
    final public static function check(?string $g): void
    {
        \assert(\is_string($g) && $g !== '');
        if (\extension_loaded('pcre') && @preg_match('/^.$/u', "é") !== 1) {
            throw new UnsupportedEnvironmentException('pcre', \true);
        }
        \assert($g !== null);
        echo \strlen($g) > 0 ? 'yes' : 'no';
    }
}

PHP;

    private const FILE = 'src/Internal/Environment.php';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/embed.php';
    }

    public function testThePatchesRemoveWhatTheSnifferRefusesAndNothingElse(): void
    {
        $counts = [];
        $patched = $this->embedded()->patch('sattleme/checkout', self::FILE, self::SCOPED, $counts);

        $this->assertSame(['final' => 2, 'assert' => 2, 'ArrayObject' => 0, '@' => 1], $counts);
        $this->assertStringNotContainsString('final', $patched);
        $this->assertStringNotContainsString('assert', $patched);
        $this->assertStringContainsString(
            "\\Sattleme\\Payment\\Embedded\\Silence::call(static fn() => preg_match('/^.\$/u', \"é\")) !== 1",
            $patched
        );
        $this->assertStringContainsString("{\n        if (", $patched);
        // Still PHP: parsing it throws nothing.
        $this->assertNotEmpty(\PhpToken::tokenize($patched, TOKEN_PARSE));
    }

    public function testThePatchedFileIsTheOriginalButForThePrefixAndThePatches(): void
    {
        $counts = [];
        $patched = $this->embedded()->patch('sattleme/checkout', self::FILE, self::SCOPED, $counts);
        $found = [];

        $this->assertNull($this->difference(self::ORIGINAL, $patched, $found));
        $this->assertSame(
            ['final' => 2, 'assert' => 2, 'ArrayObject' => 0, '@' => 1, 'prefix' => 3, 'qualified' => 1],
            array_diff_key($found, ['same' => 0])
        );
    }

    public function testAnyOtherChangeIsFound(): void
    {
        $counts = [];
        $patched = $this->embedded()->patch('sattleme/checkout', self::FILE, self::SCOPED, $counts);
        $changes = [
            'a string' => ["'yes'", "'no'"],
            'a statement' => ["        echo", "        \$x = 1;\n        echo"],
            'a statement left out' => ["        echo \\strlen(\$g) > 0 ? 'yes' : 'no';\n", ''],
            'the call silenced' => ["preg_match('/^.\$/u', \"é\")", "preg_match('/^.\$/', \"é\")"],
            'the operator dropped' => [
                "\\Sattleme\\Payment\\Embedded\\Silence::call(static fn() => preg_match('/^.\$/u', \"é\"))",
                "preg_match('/^.\$/u', \"é\")",
            ],
            'a prefix elsewhere' => ['class Environment', 'class Sattleme\\Payment\\Vendor\\Environment'],
            'a name qualified that is not PHP\'s' => [
                'UnsupportedEnvironmentException(',
                '\\UnsupportedEnvironmentException(',
            ],
            'ArrayIterator where nothing was bound' => ['?string $g', '?\\ArrayIterator $g'],
        ];
        foreach ($changes as $change => [$from, $to]) {
            $changed = preg_replace('/' . preg_quote($from, '/') . '/', $to, $patched, 1);
            $this->assertIsString($changed);
            $this->assertNotSame($patched, $changed, $change);
            $found = [];
            $this->assertIsString($this->difference(self::ORIGINAL, $changed, $found), $change);
        }
    }

    public function testAnAssertionThatDoesMoreThanReadIsNeverRemoved(): void
    {
        $counts = [];
        $patched = $this->embedded()->patch('sattleme/checkout', self::FILE, self::SCOPED, $counts);
        $assertions = ['\assert($this->ready());', '\assert(($g = \trim($g)) !== \'\');', '\assert(count($g) > 0);'];
        foreach ($assertions as $assertion) {
            $scoped = str_replace('\assert($g !== null);', $assertion, self::SCOPED);
            try {
                $this->embedded()->patch('sattleme/checkout', self::FILE, $scoped, $counts);
                $this->fail('Removed: ' . $assertion);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('an assert() that ', $e->getMessage());
            }
            // Nor taken as removed by the comparison.
            $original = str_replace('\assert($g !== null);', $assertion, self::ORIGINAL);
            $found = [];
            $this->assertStringContainsString(
                'an assert() that ',
                (string) $this->difference($original, $patched, $found),
                $assertion
            );
        }
    }

    public function testAPatchBoundToAFileRefusesAnotherCount(): void
    {
        $scoped = str_replace('@preg_match', 'preg_match', self::SCOPED);
        $counts = [];

        $this->expectExceptionMessage('uses "@" 0 times: its patch is proven for 1');
        $this->embedded()->patch('sattleme/checkout', self::FILE, $scoped, $counts);
    }

    public function testReferencesWithoutThePrefixAreFound(): void
    {
        $files = [
            'Model/Qr.php' => "<?php\nnamespace Sattleme\\Payment\\Model;\n\nuse BaconQrCode\\Writer;\n"
                . "use Sattleme\\Payment\\Vendor\\BaconQrCode\\Encoder\\Encoder;\n"
                . "// BaconQrCode\\Writer, in a comment\n"
                . "\$a = 'Sattleme\\\\Checkout\\\\Client';\n\$b = \"%%Creator: BaconQrCode\\n\";\n"
                . "\$c = '\\\\Sattleme\\\\Payment\\\\Vendor\\\\DASPRiD\\\\Enum\\\\AbstractEnum';\n",
            'etc/di.xml' => "<config><!-- Sattleme\\Checkout\\Client -->"
                . "<type name=\"Sattleme\\Checkout\\Client\"/>"
                . "<type name=\"Sattleme\\Payment\\Vendor\\Sattleme\\Checkout\\Client\"/></config>",
            'view/frontend/templates/page.phtml' => "<?php /** @var \\DASPRiD\\Enum\\AbstractEnum \$e */ ?>\n"
                . "<?= \\DASPRiD\\Enum\\NullValue::class ?>",
            'README.md' => 'BaconQrCode\\Writer',
        ];

        $this->assertSame(
            [
                'Model/Qr.php, line 4: BaconQrCode\\Writer',
                'Model/Qr.php, line 7: Sattleme\\Checkout\\C',
                'etc/di.xml: Sattleme\\Checkout\\C',
                'view/frontend/templates/page.phtml, line 2: \\DASPRiD\\Enum\\NullValue',
            ],
            $this->embedded()->unprefixedReferences($files)
        );
    }

    /**
     * What tokenDifference() finds between $original and $embedded, as the SDK's FILE.
     *
     * @param string $original The original, reprinted.
     * @param string $embedded The embedded file.
     * @param array<string,int> $found What it compared, by kind.
     * @return string|null
     */
    private function difference(string $original, string $embedded, array &$found): ?string
    {
        $tokens = static fn(string $code): array => array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn(\PhpToken $token): bool => $token->id !== T_WHITESPACE
        ));

        return $this->embedded()
            ->tokenDifference('sattleme/checkout', self::FILE, $tokens($original), $tokens($embedded), $found);
    }

    /**
     * The tool, on this repository.
     *
     * @return Embedded
     */
    private function embedded(): Embedded
    {
        return new Embedded(dirname(__DIR__, 3));
    }
}
