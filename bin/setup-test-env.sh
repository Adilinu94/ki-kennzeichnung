#!/usr/bin/env bash
# Builds a throw-away WordPress (SQLite, no MySQL needed) with Elementor and this plugin
# for the integration tests. Needs: php (gd, sqlite3/pdo_sqlite, curl, mbstring, xml, zip), git.
#
#   bin/setup-test-env.sh /tmp/kikz-env
#   KIKZ_WP_LOAD=/tmp/kikz-env/wp/wp-load.php php tests/integration/run.php
#   KIKZ_WP_DIR=/tmp/kikz-env/wp tests/integration/e2e.sh
set -euo pipefail

ENV_DIR="${1:?usage: setup-test-env.sh <target-dir>}"
WP_VERSION="${WP_VERSION:-7.1.3}"
ELEMENTOR_VERSION="${ELEMENTOR_VERSION:-v3.35.9}"
SQLITE_VERSION="${SQLITE_VERSION:-v3.1.0}"
PORT="${KIKZ_PORT:-8099}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"

mkdir -p "$ENV_DIR" && cd "$ENV_DIR"
[ -d wp ] || git clone -q --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git wp
[ -d sqlite-src ] || git clone -q --depth 1 --branch "$SQLITE_VERSION" https://github.com/WordPress/sqlite-database-integration.git sqlite-src
[ -d elementor-src ] || git clone -q --depth 1 --branch "$ELEMENTOR_VERSION" https://github.com/elementor/elementor.git elementor-src

P=wp/wp-content/plugins
mkdir -p "$P" wp/wp-content/database
if [ ! -d "$P/sqlite-database-integration" ]; then
  cp -r sqlite-src/packages/plugin-sqlite-database-integration "$P/sqlite-database-integration"
  rm -rf "$P/sqlite-database-integration/wp-includes/database"
  cp -r sqlite-src/packages/mysql-on-sqlite/src "$P/sqlite-database-integration/wp-includes/database"
  cp "$P/sqlite-database-integration/db.copy" wp/wp-content/db.php
  sed -i "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" wp/wp-content/db.php
fi
[ -d "$P/elementor" ] || cp -r elementor-src "$P/elementor"
ln -sfn "$PLUGIN_DIR" "$P/ki-kennzeichnung"

cat > wp/wp-config.php <<PHP
<?php
define('DB_NAME','wp'); define('DB_USER','x'); define('DB_PASSWORD','x'); define('DB_HOST','localhost'); define('DB_CHARSET','utf8'); define('DB_COLLATE','');
define('AUTH_KEY','a');define('SECURE_AUTH_KEY','a');define('LOGGED_IN_KEY','a');define('NONCE_KEY','a');define('AUTH_SALT','a');define('SECURE_AUTH_SALT','a');define('LOGGED_IN_SALT','a');define('NONCE_SALT','a');
\$table_prefix='wp_';
define('WP_DEBUG',true); define('WP_DEBUG_LOG',true); define('WP_DEBUG_DISPLAY',false);
define('WP_HOME','http://localhost:$PORT'); define('WP_SITEURL','http://localhost:$PORT');
define('DISABLE_WP_CRON',true); define('FS_METHOD','direct');
if(!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
require_once ABSPATH.'wp-settings.php';
PHP

cat > install.php <<'PHP'
<?php
define('WP_INSTALLING', true);
require __DIR__.'/wp/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) { wp_install('Test','admin','admin@example.test',true,'','password'); echo "WordPress installed\n"; }
PHP
php install.php

cat > activate.php <<'PHP'
<?php
require __DIR__.'/wp/wp-load.php';
update_option('active_plugins', ['ki-kennzeichnung/ki-kennzeichnung.php','elementor/elementor.php']);
// Elementor 4 style atomic elements (e-div-block, e-flexbox, e-image) are an experiment in 3.x.
update_option('elementor_experiment-e_atomic_elements','active');
update_option('elementor_experiment-e_classes','active');
echo "plugins activated\n";
PHP
php activate.php

# Elementor initialises itself on its first load (it writes elementor_install_history and only then
# treats the site as a new install with the Container feature active). Warm it up, and fail loudly
# if the Container feature is still off, instead of letting the Elementor tests fail confusingly.
cat > warmup.php <<'PHP'
<?php
require __DIR__.'/wp/wp-load.php';
$x = \Elementor\Plugin::$instance->experiments;
echo ($x->is_feature_active('container') && $x->is_feature_active('e_atomic_elements')) ? "container=yes\n" : "container=no\n";
PHP
ready=no
for attempt in 1 2 3; do
  if php warmup.php 2>/dev/null | grep -q 'container=yes'; then ready=yes; break; fi
done
if [ "$ready" != yes ]; then echo "Elementor Container feature did not become active" >&2; exit 1; fi
echo "Elementor warmed up"
echo
echo "Ready. Next:"
echo "  KIKZ_WP_LOAD=$ENV_DIR/wp/wp-load.php php $PLUGIN_DIR/tests/integration/run.php"
echo "  KIKZ_WP_DIR=$ENV_DIR/wp KIKZ_PORT=$PORT $PLUGIN_DIR/tests/integration/e2e.sh"
