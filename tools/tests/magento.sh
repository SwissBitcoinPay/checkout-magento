#!/bin/sh
# shellcheck disable=SC2016 # The PHP of `php -r '…'`: its variables are PHP's, not the shell's.
# A Magento Open Source to test the module in, without Docker nor root: Magento's own repository
# at a release's tag, its Composer lock installed, and the module installed in it as a shop
# installs it from Packagist: from its archive (build/dist, tools/build.php: the files of the
# commit's git archive, which Packagist distributes), with Composer. The archive holds the
# libraries the module embeds (the SDK, the QR code encoder), prefixed: the tests run on them, as
# shops get them.
#
#   sh tools/tests/magento.sh prepare <magento tag> <dir>
#       Clones Magento at <tag> into <dir> (shallow; a clone already there is kept), installs its
#       lock, the module and its tests' autoloader (Sattleme\Payment\Test\ from Test/), and the
#       tests' configuration.
#   sh tools/tests/magento.sh module <dir>
#       Puts the files of build/dist's archive in place of those installed (after a change, and
#       tools/build.php build).
#   sh tools/tests/magento.sh unit <dir>
#       The module's unit tests, in Magento's unit test framework.
#   sh tools/tests/magento.sh integration <dir>
#       The module's integration tests, in Magento's integration test framework, on the MariaDB of
#       tools/tests/mariadb.sh (started if needed), in a database of its own (magento_it).
#
# PHP and Composer are those on PATH.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMMAND="${1:-}"
DIR="${3:-${2:-}}"
if [ -z "$COMMAND" ] || [ -z "$DIR" ]; then
  sed -n '3,23p' "$0" >&2
  exit 2
fi

module_version() {
  php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$ROOT/composer.json"
}

case "$COMMAND" in
prepare)
  TAG="$2"
  if [ ! -f "$ROOT/build/dist/sattleme-module-payment-$(module_version).zip" ]; then
    php "$ROOT/tools/build.php" build
  fi
  if [ ! -d "$DIR/.git" ]; then
    git clone -q --depth 1 --branch "$TAG" -c advice.detachedHead=false https://github.com/magento/magento2.git "$DIR"
  fi
  cd "$DIR"
  # The module from its archive, as Composer installs the package Packagist distributes.
  composer config repositories.sattleme-module artifact "$ROOT/build/dist"
  php -r '
    $file = $argv[1];
    $composer = json_decode(file_get_contents($file), true);
    $composer["autoload-dev"]["psr-4"]["Sattleme\\Payment\\Test\\"] = $argv[2] . "/Test/";
    file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  ' composer.json "$ROOT"
  rm -rf "$(composer config cache-files-dir)/sattleme"
  composer require --no-update --no-interaction "sattleme/module-payment:$(module_version)"
  # Magento's lock as it is, plus the module, which brings nothing else.
  composer update --no-interaction --no-progress sattleme/module-payment
  php "$ROOT/tools/tests/phpunit.php" "$DIR" "$ROOT/Test" "${SATTLEME_DB:-127.0.0.1:3306}" magento_it
  ;;
module)
  # After a change, the archive's files in place of those installed (Composer would take its
  # cached copy, of the same version): what Composer extracts from it, the autoloader unchanged.
  php -r '
    [, $zip, $target] = $argv;
    $archive = new ZipArchive();
    if ($archive->open($zip, ZipArchive::RDONLY) !== true) {
        fwrite(STDERR, "Cannot open $zip\n");
        exit(1);
    }
    $remove = function (string $dir) use (&$remove): void {
        foreach (array_diff(scandir($dir) ?: [], [".", ".."]) as $entry) {
            is_dir("$dir/$entry") && !is_link("$dir/$entry") ? $remove("$dir/$entry") : unlink("$dir/$entry");
        }
        rmdir($dir);
    };
    if (is_dir($target)) {
        $remove($target);
    }
    $archive->extractTo($target) || exit(1);
  ' "$ROOT/build/dist/sattleme-module-payment-$(module_version).zip" "$DIR/vendor/sattleme/module-payment"
  ;;
unit)
  cd "$DIR/dev/tests/unit"
  php -d memory_limit=-1 ../../../vendor/bin/phpunit -c phpunit-sattleme.xml
  ;;
integration)
  DB="$(sh "$ROOT/tools/tests/mariadb.sh" start)"
  sh "$ROOT/tools/tests/mariadb.sh" create magento_it
  PATH="$(sh "$ROOT/tools/tests/mariadb.sh" bin):$PATH"
  export PATH
  php "$ROOT/tools/tests/phpunit.php" "$DIR" "$ROOT/Test" "$DB" magento_it
  # Never the end-to-end test's module, which fakes the SDK for all of Magento (tools/tests/e2e.sh):
  # these tests fake it themselves, at their own seam.
  rm -rf "$DIR/app/code/Sattleme/PaymentE2e" "$DIR/dev/tests/integration/tmp/sandbox-"*
  cd "$DIR/dev/tests/integration"
  php -d memory_limit=-1 ../../../vendor/bin/phpunit -c phpunit-sattleme.xml
  ;;
*)
  sed -n '3,23p' "$0" >&2
  exit 2
  ;;
esac
