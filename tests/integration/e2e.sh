#!/usr/bin/env bash
# Starts PHP's built-in server for the WordPress install, runs the e2e suite, stops the server.
#   KIKZ_WP_DIR=/path/to/wordpress KIKZ_PORT=8099 tests/integration/e2e.sh
set -e
DIR="${KIKZ_WP_DIR:?set KIKZ_WP_DIR to the WordPress directory}"
PORT="${KIKZ_PORT:-8099}"
php -S "localhost:$PORT" -t "$DIR" > /tmp/kikz-e2e-server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "http://localhost:$PORT/" && break; sleep 0.3; done
KIKZ_WP_LOAD="$DIR/wp-load.php" php "$(dirname "$0")/run.php" e2e
