<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Tools\SdkPin;

/**
 * The pinned copy of the SDK (sdk/): PIN.json's files, unchanged, at the version the lock of the
 * embedded libraries (tools/embed/composer.lock) takes from it.
 */
class SdkPinTest extends TestCase
{
    /**
     * @var string|null
     */
    private ?string $scratch = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/sdk.php';
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== null) {
            foreach (array_reverse((new SdkPin())->filesUnder($this->scratch)) as $file) {
                unlink($this->scratch . '/' . $file);
            }
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->scratch, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $dir) {
                if ($dir instanceof \SplFileInfo) {
                    rmdir($dir->getPathname());
                }
            }
            rmdir($this->scratch);
        }
        parent::tearDown();
    }

    public function testTheCopyIsThePinnedOne(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertSame([], (new SdkPin())->problems($root . '/sdk'));
        $pin = new SdkPin();
        $version = $pin->version($root . '/sdk');
        $this->assertSame($version, $pin->embeddedVersion($root . '/tools/embed/composer.json'));
        $lock = json_decode((string) file_get_contents($root . '/tools/embed/composer.lock'), true);
        $this->assertIsArray($lock);
        $this->assertIsArray($lock['packages']);
        $locked = array_values(array_filter(
            $lock['packages'],
            static fn(mixed $package): bool => is_array($package) && ($package['name'] ?? null) === SdkPin::PACKAGE
        ));
        $this->assertCount(1, $locked);
        $this->assertSame($version, $locked[0]['version']);
        $this->assertSame(['type' => 'path', 'url' => '../../sdk'], array_intersect_key(
            is_array($locked[0]['dist']) ? $locked[0]['dist'] : [],
            ['type' => true, 'url' => true]
        ));
    }

    public function testTheImportSetsTheVersionTheEmbeddedLibrariesLock(): void
    {
        $root = dirname(__DIR__, 3);
        $pin = new SdkPin();
        $version = $pin->version($root . '/sdk');
        $original = (string) file_get_contents($root . '/tools/embed/composer.json');
        $manifest = $this->copy() . '/embed.json';
        file_put_contents($manifest, $original);

        $pin->setEmbeddedVersion($manifest, '9.8.7');

        $this->assertSame('9.8.7', $pin->embeddedVersion($manifest));
        $this->assertSame(
            str_replace('"sattleme/checkout": "' . $version . '"', '"sattleme/checkout": "9.8.7"', $original),
            (string) file_get_contents($manifest)
        );
        // Its "require" and its path repository naming two versions: neither is the one locked.
        $other = preg_replace('/"sattleme\/checkout": "[0-9.]+"/', '"sattleme/checkout": "9.8.8"', $original, 1);
        file_put_contents($manifest, (string) $other);
        $this->assertNull($pin->embeddedVersion($manifest));
    }

    public function testAChangedOrAddedFileIsFound(): void
    {
        $copy = $this->copy();
        file_put_contents($copy . '/src/Client.php', "\n", FILE_APPEND);
        file_put_contents($copy . '/src/Extra.php', "<?php\n");

        $this->assertSame(
            ['src/Client.php differs from PIN.json', 'src/Extra.php is not in PIN.json'],
            (new SdkPin())->problems($copy)
        );
    }

    /**
     * A copy of sdk/ in a directory of the test's own.
     *
     * @return string
     */
    private function copy(): string
    {
        $source = dirname(__DIR__, 3) . '/sdk';
        $this->scratch = sys_get_temp_dir() . '/sattleme-sdk-pin-' . bin2hex(random_bytes(6));
        foreach ((new SdkPin())->filesUnder($source) as $file) {
            $target = $this->scratch . '/' . $file;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy($source . '/' . $file, $target);
        }

        return $this->scratch;
    }
}
