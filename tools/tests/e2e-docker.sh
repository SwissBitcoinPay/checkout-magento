#!/bin/sh
# The end-to-end test (tools/tests/e2e.sh) in Docker containers, as a developer runs it on a
# machine with Docker: MariaDB in the official image, the shop in Ubuntu's, with a PHP binary of
# the host's (a static build: nothing to install in the container). The containers and their
# network are this run's own (their names carry its id), removed at the end.
#
#   sh tools/tests/e2e-docker.sh <magento dir> <php binary> [<php.ini directory>]
#
# <magento dir> is prepared by tools/tests/magento.sh prepare; it, this repository and the PHP
# binary are mounted at their own paths.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="$(cd "${1:?Usage: sh tools/tests/e2e-docker.sh <magento dir> <php binary> [<php.ini dir>]}" && pwd)"
PHP="${2:?Usage: sh tools/tests/e2e-docker.sh <magento dir> <php binary> [<php.ini dir>]}"
INI="${3:-}"
MARIADB=mariadb:11.4.13@sha256:250e1609e93c0e6aecf64bfbab2d84d5e89d2481540fe77447ff6c855c3b1873
UBUNTU=ubuntu:24.04@sha256:73b999393d07f2bf65a40373778a44dc9a6d54ad7718d29247d8ff9413c4d6a8
RUN="sattleme-m2-e2e-$(date +%s)-$$"

cleanup() {
  docker rm -f -v "$RUN-shop" "$RUN-db" >/dev/null 2>&1 || true
  docker network rm "$RUN-net" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

docker network create "$RUN-net" >/dev/null
docker run -d --name "$RUN-db" --network "$RUN-net" \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=magento_e2e \
  -e MARIADB_USER=magento -e MARIADB_PASSWORD=magento \
  "$MARIADB" --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci >/dev/null
tries=0
# Over TCP, as the shop connects: the image's first start runs a temporary server for its set-up,
# without networking, which answers on its socket before the real one starts.
until docker exec "$RUN-db" mariadb --protocol=tcp -h127.0.0.1 -umagento -pmagento -e 'SELECT 1' magento_e2e \
  >/dev/null 2>&1; do
  tries=$((tries + 1))
  [ "$tries" -le 120 ] || { docker logs "$RUN-db" >&2; exit 1; }
  sleep 1
done

set -- -v "$ROOT:$ROOT:ro" -v "$DIR:$DIR" -v "$PHP:/usr/local/bin/php:ro"
if [ -n "$INI" ]; then
  set -- "$@" -v "$INI:/etc/sattleme-php:ro" -e PHPRC=/etc/sattleme-php
fi
docker run --name "$RUN-shop" --network "$RUN-net" "$@" -w "$DIR" "$UBUNTU" \
  sh "$ROOT/tools/tests/e2e.sh" "$DIR" "$RUN-db:3306"
