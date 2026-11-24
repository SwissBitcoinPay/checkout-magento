<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Tools\Embedded;
use Sattleme\Payment\Tools\Package;

/**
 * The package Adobe Commerce Marketplace takes (tools/build.php): its composer.json, as the
 * Marketplace's technical review checks it.
 */
class PackageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/build.php';
    }

    public function testTheModulesComposerJsonIsOneTheMarketplaceTakes(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $this->assertIsArray($composer);

        $this->assertSame([], (new Package($root))->composerProblems($composer));
        $this->assertSame('1.0.0', $composer['version']);
        // Its author, as the SDK's.
        $this->assertSame([['name' => 'sattle.me', 'homepage' => 'https://sattle.me']], $composer['authors']);
        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
        $this->assertMatchesRegularExpression('/\n## 1\.0\.0 - \d{4}-\d{2}-\d{2}\n/', $changelog);
    }

    public function testTheModuleEmbedsItsLibrariesAndRequiresWhatTheyNeed(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertIsArray($composer['require']);

        // PHP, its extensions and Magento's modules only: the libraries are in the package.
        $this->assertSame([], (new Package($root))->embeddingProblems($composer));
        // Each PHP extension an embedded library needs (tools/embed/composer.lock), the module too.
        $lock = json_decode((string) file_get_contents($root . '/tools/embed/composer.lock'), true);
        $this->assertIsArray($lock);
        $this->assertIsArray($lock['packages']);
        $needed = [];
        foreach ($lock['packages'] as $package) {
            $this->assertIsArray($package);
            $this->assertIsArray($package['require']);
            foreach (array_keys($package['require']) as $name) {
                if (str_starts_with((string) $name, 'ext-')) {
                    $needed[] = (string) $name;
                }
            }
        }
        $this->assertContains('ext-iconv', $needed);
        $this->assertContains('ext-openssl', $needed);
        $this->assertSame([], array_values(array_diff(array_unique($needed), array_keys($composer['require']))));
    }

    public function testTheReadmeListsWhatThePackageHolds(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = (string) file_get_contents($root . '/README.md');
        // Development's first paragraph: "The package holds `Block/`, …".
        if (preg_match('/\sThe package holds (`.+?)\n\n/s', $readme, $paragraph) !== 1) {
            $this->fail('README.md does not say what the package holds');
        }
        preg_match_all('/`([^`]+)`/', $paragraph[1], $named);
        require_once $root . '/tools/embed.php';
        $holds = Package::CONTENTS;
        foreach (array_keys(Embedded::PACKAGES) as $package) {
            $holds[] = Package::EMBEDDED . $package . '/';
        }
        sort($holds);
        $listed = array_values(array_diff($named[1], [Package::EMBEDDED]));
        sort($listed);

        $this->assertSame($holds, $listed);
    }

    public function testALibraryRequiredOrNotAutoloadedFromThePackageIsFound(): void
    {
        $composer = [
            'require' => [
                'php' => '~8.2.0',
                'ext-json' => '*',
                'magento/framework' => '~103.0.7',
                'sattleme/checkout' => '^0.1',
                'bacon/bacon-qr-code' => '^3.0',
            ],
            'autoload' => [
                'psr-4' => [
                    'Sattleme\\Payment\\' => '',
                    'Sattleme\\Payment\\Vendor\\BaconQrCode\\' => 'vendor/bacon/bacon-qr-code/src/',
                    'Sattleme\\Payment\\Vendor\\DASPRiD\\Enum\\' => 'vendor/dasprid/enum/',
                ],
            ],
        ];

        $this->assertSame(
            [
                'composer.json requires sattleme/checkout, which the package embeds or does not need',
                'composer.json requires bacon/bacon-qr-code, which the package embeds or does not need',
                'composer.json does not autoload Sattleme\\Payment\\Vendor\\Sattleme\\Checkout\\ from '
                . 'vendor/sattleme/checkout/src/',
                'composer.json does not autoload Sattleme\\Payment\\Vendor\\DASPRiD\\Enum\\ from '
                . 'vendor/dasprid/enum/src/',
            ],
            (new Package(dirname(__DIR__, 3)))->embeddingProblems($composer)
        );
    }

    public function testWhatTheMarketplaceRefusesIsFound(): void
    {
        $package = new Package(dirname(__DIR__, 3));
        $composer = [
            'name' => 'someone/module-payment',
            'type' => 'library',
            'version' => '0.1',
            'license' => 'proprietary',
            'autoload' => ['psr-4' => []],
            'extra' => ['map' => [], 'magento-root-dir' => '.'],
            'dist' => [],
            'require' => [
                'php' => '~8.3.0',
                'magento/product-community-edition' => '2.4.9',
                'magento/framework' => '*',
                'vendor/lib' => 'dev-main as 1.0.0',
            ],
        ];

        $this->assertSame(
            [
                'composer.json is not named sattleme/module-payment',
                'composer.json is not of type magento2-module',
                'composer.json has no version x.y.z',
                'composer.json is not of license MIT',
                'composer.json does not autoload registration.php and a PSR-4 namespace',
                'composer.json has extra.map',
                'composer.json has extra.magento-root-dir',
                'composer.json has dist',
                'composer.json requires magento/product-community-edition',
                'composer.json takes any version of magento/framework',
                'composer.json has an inline alias for vendor/lib',
                'composer.json\'s PHP constraint does not take PHP 8.2.0',
                'composer.json\'s PHP constraint does not take PHP 8.4.0',
                'composer.json\'s PHP constraint does not take PHP 8.5.0',
            ],
            $package->composerProblems($composer)
        );
    }
}
