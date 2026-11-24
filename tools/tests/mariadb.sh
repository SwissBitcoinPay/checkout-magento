#!/bin/sh
# shellcheck disable=SC2016 # The PHP of `php -r '…'`: its variables are PHP's, not the shell's.
# A MariaDB server for the tests, run by the current user: no root, no Docker, no package manager
# (nothing to install on the runner, nor on a developer's machine). MariaDB 11.4.14 (an LTS release
# that Magento 2.4.7, 2.4.8 and 2.4.9 all support: app/etc/di.xml, supportedVersionPatterns), from
# MariaDB's own packages for Ubuntu 24.04, with the libraries they need beyond Ubuntu 24.04's base
# image (the server: libaio and liburing; the mariadb client: libedit, libncurses, libbsd and
# libmd), each checked against its SHA-256 and extracted with dpkg-deb; the server and the clients
# run with the extracted libraries first. It listens on 127.0.0.1, on a free port (Magento's
# integration tests call the mysql and mysqldump clients with --host and --port).
#
# Usage:
#   sh tools/tests/mariadb.sh start   Starts it (once) and prints "127.0.0.1:<port>". Its root user
#                                     has no password; the user "magento" (password "magento")
#                                     owns every database whose name starts with "magento".
#   sh tools/tests/mariadb.sh bin     Prints a directory holding its mysql and mysqldump clients.
#   sh tools/tests/mariadb.sh create <database>   Creates (empty) a database.
#   sh tools/tests/mariadb.sh stop    Stops it.
#
# The directory is $SATTLEME_MARIADB_DIR, else $TMPDIR/sattleme-mariadb (in CI, the runner's own
# temporary directory), else build/mariadb. Downloads are kept in build/mariadb-debs.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [ -n "${SATTLEME_MARIADB_DIR:-}" ]; then
  DIR="$SATTLEME_MARIADB_DIR"
elif [ -n "${TMPDIR:-}" ]; then
  DIR="$TMPDIR/sattleme-mariadb"
else
  DIR="$ROOT/build/mariadb"
fi
DEBS="$ROOT/build/mariadb-debs"
PREFIX="$DIR/root/usr"
SOCKET="$DIR/mysqld.sock"

MARIADB=https://archive.mariadb.org/mariadb-11.4.14/repo/ubuntu/pool/main/m/mariadb
UBUNTU=https://archive.ubuntu.com/ubuntu/pool/main
# name, URL, SHA-256.
PACKAGES="
mariadb-server-core $MARIADB/mariadb-server-core_11.4.14%2Bmaria~ubu2404_amd64.deb 6f235606312c16c684992d3a2b6a3a37aa56b296524dee6ae184a9bf2f152ae8
mariadb-server $MARIADB/mariadb-server_11.4.14%2Bmaria~ubu2404_amd64.deb 46d118d92256842eb73a8cbed6c793dc42e533bdfaa97fd233e0b894645da28c
mariadb-client-core $MARIADB/mariadb-client-core_11.4.14%2Bmaria~ubu2404_amd64.deb b78d55b124e62aeefdd63e2613a0b7d320bc3b172a0dfc5c76968797be880ddd
mariadb-client $MARIADB/mariadb-client_11.4.14%2Bmaria~ubu2404_amd64.deb d2eb74dfc8c4fb3fa10d4368c78e51a15bb2a4a13ddca0e06dc7d3a4ed543d98
libaio1t64 $UBUNTU/liba/libaio/libaio1t64_0.3.113-6build1.1_amd64.deb 433212b33e26d424605c01cec1de33b63f2cc12870987b4cb7b3625baba66a19
liburing2 $UBUNTU/libu/liburing/liburing2_2.5-1build1_amd64.deb c2aef62accee92a06263c3ad4ef46132c13e409b54296c44792a20491830a7b0
libedit2 $UBUNTU/libe/libedit/libedit2_3.1-20230828-1build1_amd64.deb 3afbc8ddf835049dd4d88275b9d13680e4c4cdd59e191cbd63424152cc08e00e
libncurses6 $UBUNTU/n/ncurses/libncurses6_6.4%2B20240113-1ubuntu2.2_amd64.deb 59e8527dfd14473393960bfb7c69566f1ad9d576423421dcb8b8dcdbb3e7fca1
libbsd0 $UBUNTU/libb/libbsd/libbsd0_0.12.1-1build1.1_amd64.deb f3857b0863ac5cfd4263e9bf6cfb1d4be88d5321e4070d5bc2b62b0949e6c86f
libmd0 $UBUNTU/libm/libmd/libmd0_1.1.0-2build1.1_amd64.deb e5ba01d3c41f256aaf57ec59aa0554857162e3e7f97cdfbff1ed2c0e8d720ee7
"

install_server() {
  if [ ! -x "$PREFIX/sbin/mariadbd" ]; then
    extract
  fi
  # The clients Magento's tests call, by these names, with the extracted libraries first.
  mkdir -p "$DIR/bin"
  wrap mysql mariadb
  wrap mysqldump mariadb-dump
}

# wrap <name> <client>: $DIR/bin/<name> runs $PREFIX/bin/<client> with its arguments.
wrap() {
  printf '#!/bin/sh\nLD_LIBRARY_PATH="%s${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" exec "%s" "$@"\n' \
    "$PREFIX/lib/x86_64-linux-gnu" "$PREFIX/bin/$2" > "$DIR/bin/$1"
  chmod 755 "$DIR/bin/$1"
}

extract() {
  mkdir -p "$DEBS" "$DIR/root"
  echo "$PACKAGES" | while read -r name url sha256; do
    [ -n "$name" ] || continue
    deb="$DEBS/$name.deb"
    if [ ! -f "$deb" ] || ! echo "$sha256  $deb" | sha256sum -c --status -; then
      curl -fsSL -o "$deb.download" "$url"
      if ! echo "$sha256  $deb.download" | sha256sum -c --status -; then
        rm -f "$deb.download"
        echo "$name: its SHA-256 is not $sha256" >&2
        exit 1
      fi
      mv "$deb.download" "$deb"
    fi
    dpkg-deb -x "$deb" "$DIR/root"
  done
}

run() {
  LD_LIBRARY_PATH="$PREFIX/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" "$@"
}

client() {
  run "$PREFIX/bin/mariadb" --no-defaults --socket="$SOCKET" --user=root "$@"
}

free_port() {
  # A port the kernel gives as free now, from its ephemeral range.
  if command -v python3 >/dev/null 2>&1; then
    python3 -I -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])'
  else
    php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1], "\n";'
  fi
}

start() {
  install_server
  if [ -S "$SOCKET" ] && [ -f "$DIR/port" ] && client -e 'SELECT 1' >/dev/null 2>&1; then
    echo "127.0.0.1:$(cat "$DIR/port")"
    return
  fi
  user_option=""
  if [ "$(id -u)" = "0" ]; then
    user_option="--user=root"
  fi
  if [ ! -d "$DIR/data/mysql" ]; then
    mkdir -p "$DIR/data"
    # shellcheck disable=SC2086
    run "$PREFIX/bin/mariadb-install-db" --no-defaults --basedir="$PREFIX" --datadir="$DIR/data" \
      --auth-root-authentication-method=normal --skip-test-db $user_option >"$DIR/install.log" 2>&1 \
      || { cat "$DIR/install.log" >&2; exit 1; }
  fi
  port="$(free_port)"
  echo "$port" > "$DIR/port"
  # In a subshell of its own, redirected as a whole: a function's redirections make dash keep the
  # caller's output open in the background job (a copy of the descriptor, to restore it after),
  # and `db="$(sh tools/tests/mariadb.sh start)"` would wait for the server to end.
  # shellcheck disable=SC2086
  (run "$PREFIX/sbin/mariadbd" --no-defaults --basedir="$PREFIX" --datadir="$DIR/data" \
    --socket="$SOCKET" --pid-file="$DIR/mysqld.pid" --bind-address=127.0.0.1 --port="$port" \
    --lc-messages-dir="$PREFIX/share/mariadb" --plugin-dir="$PREFIX/lib/mysql/plugin" \
    --log-error="$DIR/error.log" --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci \
    --innodb-buffer-pool-size=256M --skip-log-bin --max-connections=200 $user_option) \
    </dev/null >/dev/null 2>&1 &
  tries=0
  until client -e 'SELECT 1' >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -gt 120 ]; then
      echo "MariaDB did not start:" >&2
      cat "$DIR/error.log" >&2 || true
      exit 1
    fi
    sleep 0.5
  done
  client -e "CREATE USER IF NOT EXISTS 'magento'@'127.0.0.1' IDENTIFIED BY 'magento'; GRANT ALL ON \`magento%\`.* TO 'magento'@'127.0.0.1'; CREATE USER IF NOT EXISTS 'magento'@'localhost' IDENTIFIED BY 'magento'; GRANT ALL ON \`magento%\`.* TO 'magento'@'localhost';"
  echo "127.0.0.1:$port"
}

create() {
  case "$1" in
    magento*) ;;
    *) echo "Not a database of the tests (magento…): $1" >&2; exit 2 ;;
  esac
  client -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\`;"
}

stop() {
  if [ -S "$SOCKET" ]; then
    client -e 'SHUTDOWN' || true
  fi
}

case "${1:-}" in
  start) start ;;
  bin) install_server; echo "$DIR/bin" ;;
  create) create "${2:-}" ;;
  stop) stop ;;
  *) echo "Usage: sh tools/tests/mariadb.sh start | bin | create <database> | stop" >&2; exit 2 ;;
esac
