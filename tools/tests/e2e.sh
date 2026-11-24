#!/bin/sh
# shellcheck disable=SC2016 # The PHP of `php -r '…'`: its variables are PHP's, not the shell's.
# The end-to-end test on the real platform: the module installed in a Magento from its archive
# (tools/tests/magento.sh prepare <tag> <dir>), Magento installed, the module enabled, compiled
# (setup:di:compile), its static files deployed and Magento in production mode (as Adobe's
# installation tests run it), a key set, then the storefront's pages checked cacheable and a
# customer's order paid through the storefront and the payment page (tools/tests/e2e.php), the
# SDK replaced by its fake: the test-only module Test/E2e/Sattleme_PaymentE2e, which the package
# never holds.
#
#   sh tools/tests/e2e.sh <dir> <db host:port>
#
# The database's user "magento" (password "magento") creates magento_e2e, emptied first. The
# storefront is served by PHP's built-in server on 127.0.0.1:$SATTLEME_E2E_PORT (by default a
# free port), with Magento's router (phpserver/router.php). No search engine: Magento's search modules are
# disabled, as in the integration tests.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${1:?Usage: sh tools/tests/e2e.sh <dir> <db host:port>}"
DB="${2:?Usage: sh tools/tests/e2e.sh <dir> <db host:port>}"
# A port the kernel gives as free now, unless one is named.
PORT="${SATTLEME_E2E_PORT:-$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')}"
BASE="http://127.0.0.1:$PORT/"

cd "$DIR"
rm -rf app/code/Sattleme/PaymentE2e var/sattleme-e2e generated/code generated/metadata pub/static/frontend
mkdir -p app/code/Sattleme
cp -R "$ROOT/Test/E2e/Sattleme_PaymentE2e" app/code/Sattleme/PaymentE2e
rm -f app/etc/env.php app/etc/config.php

# Magento's emails, sent as on a shop (PHP's sendmail_path, which Magento's transport uses on every
# release line: mail() up to 2.4.8, Symfony's native transport from 2.4.9), to a stand-in of
# sendmail that keeps each message in var/sattleme-e2e/mail/: every PHP process of the test (the
# storefront, the commands, the cron's own processes) reads it from PHP_INI_SCAN_DIR, which they
# inherit.
MAIL="$DIR/var/sattleme-e2e/mail"
mkdir -p "$MAIL" "$DIR/var/sattleme-e2e/ini"
cat >"$DIR/var/sattleme-e2e/sendmail" <<SENDMAIL
#!/bin/sh
# sendmail -t: the message on standard input.
cat >"\$(mktemp "$MAIL/mail.XXXXXX")"
SENDMAIL
chmod +x "$DIR/var/sattleme-e2e/sendmail"
printf 'sendmail_path = "%s -t"\n' "$DIR/var/sattleme-e2e/sendmail" >"$DIR/var/sattleme-e2e/ini/sendmail.ini"
PHP_INI_SCAN_DIR=":$DIR/var/sattleme-e2e/ini"
export PHP_INI_SCAN_DIR

# The search engines' modules of this Magento (their names change with its versions).
SEARCH=""
for module in OpenSearch Elasticsearch Elasticsearch7 Elasticsearch8 AdvancedSearch; do
  if [ -d "app/code/Magento/$module" ]; then
    SEARCH="${SEARCH:+$SEARCH,}Magento_$module"
  fi
done

echo "== setup:install"
php -d memory_limit=-1 bin/magento setup:install --no-interaction \
  --base-url="$BASE" --use-rewrites=0 \
  --db-host="$DB" --db-name=magento_e2e --db-user=magento --db-password=magento --cleanup-database \
  --admin-firstname=E2E --admin-lastname=Test --admin-email=e2e@example.com \
  --admin-user=e2e --admin-password=e2e-Pass-12345 --backend-frontname=admin \
  --language=en_US --currency=USD --timezone=UTC \
  --disable-modules="$SEARCH"
php bin/magento module:status Sattleme_Payment Sattleme_PaymentE2e

echo "== setup:di:compile"
php -d memory_limit=-1 bin/magento setup:di:compile

echo "== setup:static-content:deploy (the storefront's theme)"
php -d memory_limit=-1 bin/magento setup:static-content:deploy -f --area frontend --theme Magento/luma --jobs 1 en_US

echo "== production mode (compiled and deployed above)"
php bin/magento deploy:mode:set production --skip-compilation

echo "== the full page cache, as behind Varnish (Adobe's installation tests)"
# Magento then sends a cacheable page with its public headers, for Varnish to keep; the built-in
# cache keeps it itself and sends the client no-cache headers whatever the page.
php bin/magento config:set system/full_page_cache/caching_application 2

echo "== the method's settings"
php bin/magento config:set payment/sattleme/active 1
php bin/magento config:set payment/sattleme/integration_key \
  'sattleme_ck_qEVejHuCZpF4XMmBSw5p7iZYxCtm20XxzT1fnJ9VB8o.uPTAz1d8bbQaw2Jx22Wwrga2vI2F8allY5NrPC3Aogk.cmVsYXkuZXhhbXBsZQ'
php bin/magento cache:flush

echo "== the storefront, on $BASE"
php -S "127.0.0.1:$PORT" -t pub/ phpserver/router.php >var/log/e2e-server.log 2>&1 &
SERVER=$!
trap 'kill "$SERVER" 2>/dev/null || true' EXIT INT TERM
php -r '
  for ($i = 0; $i < 300; $i++) {
      $socket = @fsockopen("127.0.0.1", (int) $argv[1]);
      if ($socket !== false) {
          exit(0);
      }
      usleep(100000);
  }
  fwrite(STDERR, "The storefront did not start\n");
  exit(1);
' "$PORT"
php "$ROOT/tools/tests/e2e.php" "$DIR" "$BASE" "$DB"
