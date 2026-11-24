<?php

declare(strict_types=1);

/*
 * PHP-Scoper's configuration (tools/embed.php): the libraries of tools/embed/composer.lock, which
 * tools/embed.php installs in build/embed/vendor, prefixed with Sattleme\Payment\Vendor, so that
 * another module embedding or installing another version of one of them never collides with this
 * one (PORTING.md, "Packages that depend on an SDK"; checkout-prestashop's scoper.inc.php does the
 * same). Their code and their licences only, never their tests nor their tools.
 *
 * dasprid/enum's EnumMap.php is left out: BaconQrCode, the library's only user, never uses it
 * (tools/embed.php says why it must go, and checks that nothing refers to it).
 *
 * PHP-Scoper prints each file again from its syntax tree; for PHP 8.2, the oldest version the
 * module supports, whatever PHP runs it, so that every build of the lock gives the same bytes.
 */

use Isolated\Symfony\Component\Finder\Finder;
use Sattleme\Payment\Tools\Embedded;

require_once dirname(__DIR__) . '/embed.php';
$vendor = dirname(__DIR__, 2) . '/build/embed/vendor';

return [
    'prefix' => rtrim(Embedded::PREFIX, '\\'),
    'php-version' => '8.2',
    'output-dir' => dirname(__DIR__, 2) . '/build/embed/scoped',
    'finders' => [
        Finder::create()
            ->files()
            ->in([$vendor . '/sattleme/checkout', $vendor . '/bacon/bacon-qr-code'])
            ->path(['#^src/#', '#^LICENSE$#'])
            ->name(['*.php', 'LICENSE']),
        Finder::create()
            ->files()
            ->in($vendor . '/dasprid/enum')
            ->path(['#^src/#', '#^LICENSE$#'])
            ->notPath('#^src/EnumMap\.php$#')
            ->name(['*.php', 'LICENSE']),
    ],
];
