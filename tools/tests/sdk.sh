#!/bin/sh
# shellcheck disable=SC2016 # The PHP of `php -r '…'`: its variables are PHP's, not the shell's.
# The SDK's own test suite (sattleme/checkout-php: tests/, its conformance vectors in spec/), run
# against the SDK the package ships: the copy in the module's archive (build/dist, tools/build.php),
# prefixed with Sattleme\Payment\Vendor and patched as tools/embed.php says, with the module's
# Embedded\Silence it calls. As the SDK runs its suite against a copy prefixed for a plugin
# (checkout-php's tools/prefix/build.sh): the tests' namespace prefixed as the code's, PHPUnit
# from the SDK's own development dependencies (its composer.lock), its phpunit.xml.dist.
#
#   sh tools/tests/sdk.sh <checkout-php clone>
#
# The clone is at the commit sdk/PIN.json names (checked), its development dependencies
# installed (composer install in it). The tests that need libsecp256k1's cases (the group
# "differential") are left out, as the SDK's phpunit.xml.dist leaves them out.
#
# One line of one test is changed with the patch: tests/Vectors/RelayReadsVectorsTest.php makes
# the \ArrayObject it hands to the SDK's Internal\Nostr\PagedRead::read(), whose parameter the
# patch types \ArrayIterator (tools/embed.php, ARRAY_OBJECTS): it makes an \ArrayIterator. The
# script fails if that line is not there, or if any other test names \ArrayObject in a call of
# the SDK.
#
# PHP and Composer are those on PATH.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CLONE="$(cd "${1:?Usage: sh tools/tests/sdk.sh <checkout-php clone>}" && pwd)"
PREFIX='Sattleme\Payment\Vendor'
VERSION="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$ROOT/composer.json")"
ZIP="$ROOT/build/dist/sattleme-module-payment-$VERSION.zip"
WORK="$ROOT/build/sdk-tests"

# 1. The clone is the pinned SDK: its commit, and each file of sdk/ the same.
COMMIT="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["commit"];' "$ROOT/sdk/PIN.json")"
HEAD="$(git -C "$CLONE" rev-parse HEAD)"
if [ "$HEAD" != "$COMMIT" ]; then
  echo "The clone is at $HEAD, not at sdk/PIN.json's $COMMIT" >&2
  exit 1
fi
php -r '
  [, $pin, $clone] = $argv;
  $files = json_decode(file_get_contents($pin . "/PIN.json"), true)["files"];
  foreach ($files as $file => $hash) {
      if (!is_file("$clone/$file") || hash_file("sha256", "$clone/$file") !== $hash) {
          fwrite(STDERR, "The clone'"'"'s $file is not sdk/PIN.json'"'"'s\n");
          exit(1);
      }
  }
' "$ROOT/sdk" "$CLONE"
if [ ! -f "$CLONE/vendor/autoload.php" ]; then
  echo "The clone has no development dependencies: composer install in it" >&2
  exit 1
fi
if [ ! -f "$ZIP" ]; then
  echo "No archive $ZIP: php tools/build.php build" >&2
  exit 1
fi

# 2. The package's files, as the archive holds them.
rm -rf "$WORK"
mkdir -p "$WORK/package" "$WORK/run/.github/workflows" "$WORK/run/tools/differential"
php -r '
  $zip = new ZipArchive();
  ($zip->open($argv[1], ZipArchive::RDONLY) === true && $zip->extractTo($argv[2])) || exit(1);
' "$ZIP" "$WORK/package"
SDK="$WORK/package/vendor/sattleme/checkout/src"
if grep -rl --include='*.php' '^namespace Sattleme\\Checkout' "$SDK"; then
  echo "Files of the embedded SDK are not prefixed" >&2
  exit 1
fi

# 3. The SDK's tests, their namespace prefixed as the code's (and what its README's test reads).
cp -r "$CLONE/tests" "$CLONE/spec" "$CLONE/README.md" "$CLONE/composer.json" "$WORK/run/"
cp "$CLONE/.github/workflows/ci.yml" "$WORK/run/.github/workflows/"
cp "$CLONE/tools/differential/requirements.txt" "$WORK/run/tools/differential/"
find "$WORK/run/tests" -name '*.php' -exec sed -i \
  -e 's/Sattleme\\Checkout\\/Sattleme\\Payment\\Vendor\\Sattleme\\Checkout\\/g' \
  -e 's/Sattleme\\\\Checkout\\\\/Sattleme\\\\Payment\\\\Vendor\\\\Sattleme\\\\Checkout\\\\/g' {} +
# The patch's one consequence for a test (above).
VECTORS="$WORK/run/tests/Vectors/RelayReadsVectorsTest.php"
if [ "$(grep -c 'new \\ArrayObject()' "$VECTORS")" != 1 ]; then
  echo "tests/Vectors/RelayReadsVectorsTest.php does not make one \\ArrayObject: the patch's line moved" >&2
  exit 1
fi
sed -i 's/new \\ArrayObject()/new \\ArrayIterator()/' "$VECTORS"
if grep -rn --include='*.php' 'ArrayObject' "$WORK/run/tests" | grep -v -e 'tests/Unit/Loop/LoopTest.php' \
  -e 'tests/Vectors/BitcoinNodesVectorsTest.php' -e '@var \\ArrayObject' -e '@param \\ArrayObject'; then
  echo "A test names \\ArrayObject where the patch was not proven: see tools/embed.php" >&2
  exit 1
fi
cat > "$WORK/run/bootstrap.php" <<PHP
<?php

declare(strict_types=1);

// PHPUnit from the SDK's development dependencies, never its unprefixed SDK.
\$loader = require '$CLONE/vendor/autoload.php';
\$loader->setPsr4('Sattleme\\\\Checkout\\\\', []);
\$loader->setPsr4('Sattleme\\\\Checkout\\\\Tests\\\\', []);
\$loader->addPsr4('Sattleme\\\\Payment\\\\Vendor\\\\Sattleme\\\\Checkout\\\\Tests\\\\', __DIR__ . '/tests');
// The SDK the archive ships, and the module's class its patch calls.
\$loader->addPsr4('Sattleme\\\\Payment\\\\Vendor\\\\Sattleme\\\\Checkout\\\\', '$SDK');
\$loader->addPsr4('Sattleme\\\\Payment\\\\Embedded\\\\', '$WORK/package/Embedded');
register_shutdown_function(static function (): void {
    foreach (get_declared_classes() as \$class) {
        if (str_starts_with(\$class, 'Sattleme\\\\Checkout\\\\')) {
            fwrite(STDERR, "The unprefixed SDK was loaded: {\$class}\\n");
            exit(1);
        }
    }
});
PHP
sed -e 's#bootstrap="vendor/autoload.php"#bootstrap="bootstrap.php"#' \
    -e 's#vendor/phpunit/phpunit/phpunit.xsd#'"$CLONE"'/vendor/phpunit/phpunit/phpunit.xsd#' \
    -e 's#cacheDirectory=".phpunit.cache"#cacheDirectory="'"$WORK"'/.phpunit.cache"#' \
    -e 's#<directory>src</directory>#<directory>'"$SDK"'</directory>#' \
    "$CLONE/phpunit.xml.dist" > "$WORK/run/phpunit.xml"

# 4. The suite.
php "$CLONE/vendor/bin/phpunit" -c "$WORK/run/phpunit.xml"
echo "The SDK's own test suite passed against the SDK the archive ships"
