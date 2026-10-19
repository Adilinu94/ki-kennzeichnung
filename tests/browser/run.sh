#!/usr/bin/env bash
# Starts the PHP server, creates the fixtures and runs the real-browser test.
#   KIKZ_WP_DIR=/path/to/wordpress tests/browser/run.sh
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
DIR="${KIKZ_WP_DIR:?set KIKZ_WP_DIR}"
PORT="${KIKZ_PORT:-8099}"
cd "$HERE" && [ -d node_modules ] || npm install --no-audit --no-fund --silent
php -S "localhost:$PORT" -t "$DIR" > /tmp/kikz-browser-server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "http://localhost:$PORT/" && break; sleep 0.3; done
KIKZ_WP_LOAD="$DIR/wp-load.php" php "$HERE/fixtures.php" 2>/dev/null > /tmp/kikz-fixtures.json
FIXTURES=/tmp/kikz-fixtures.json node "$HERE/browser.test.mjs"
