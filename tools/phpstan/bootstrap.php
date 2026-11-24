<?php

/**
 * PHPStan's bootstrap: the autoloader of the Magento PHPStan analyses the module against
 * (SATTLEME_MAGENTO_ROOT, as tools/tests/magento.sh prepares it, the module and the SDK installed
 * in it), so that Magento's classes are its own; the module's classes and tests from this
 * repository first, never the copy installed in that Magento.
 */

declare(strict_types=1);

$root = getenv('SATTLEME_MAGENTO_ROOT');
if (!is_string($root) || !is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "SATTLEME_MAGENTO_ROOT must name a Magento with the module installed (tools/tests/magento.sh)\n");
    exit(1);
}
require_once $root . '/vendor/autoload.php';

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Sattleme\\Payment\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = dirname(__DIR__, 2) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    },
    true,
    true
);
