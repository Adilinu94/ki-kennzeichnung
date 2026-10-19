#!/usr/bin/env bash
# Rebuilds what a git checkout of Elementor lacks, so the atomic (Elementor 4) elements can be
# rendered and the real-browser tests have Elementor's CSS: scoped Twig + compiled stylesheets.
# Run after bin/setup-test-env.sh.   Needs: git, php, node/npm.
#
#   bin/build-elementor-assets.sh /tmp/kikz-env
set -euo pipefail
ENV_DIR="$(cd "${1:?usage: build-elementor-assets.sh <env-dir>}" && pwd)"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
EL="$ENV_DIR/wp/wp-content/plugins/elementor"
cd "$ENV_DIR"

[ -d twig-src ] || git clone -q --depth 1 --branch v3.11.3 https://github.com/twigphp/Twig.git twig-src
[ -d dc-src ]   || git clone -q --depth 1 https://github.com/symfony/deprecation-contracts.git dc-src
php "$PLUGIN_DIR/bin/build-elementor-deps.php" "$ENV_DIR/twig-src" "$ENV_DIR/dc-src" "$EL"

mkdir -p wp/wp-content/mu-plugins
cat > wp/wp-content/mu-plugins/elementor-deps.php <<'PHP'
<?php
// Test environment only: autoload Elementor's rebuilt, prefixed Twig.
$f = WP_PLUGIN_DIR . '/elementor/vendor_prefixed/autoload-shim.php';
if ( is_file( $f ) ) {
	require_once $f;
}
PHP

mkdir -p tools && cd tools
[ -f package.json ] || npm init -y > /dev/null
[ -d node_modules/sass ] || npm install --no-audit --no-fund --silent sass@1.77
cd "$ENV_DIR"
node "$PLUGIN_DIR/bin/build-elementor-css.mjs" "$EL" "$ENV_DIR/tools/node_modules/sass"
echo "Elementor assets built"
