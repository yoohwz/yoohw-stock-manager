#!/usr/bin/env bash
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${YOOHWSM_TEST_WP_PATH:?Set YOOHWSM_TEST_WP_PATH to a disposable WordPress installation}"
PHP_BIN="${YOOHWSM_TEST_PHP_BIN:-php}"
WP_CLI="${YOOHWSM_TEST_WP_CLI:-wp}"
"$PHP_BIN" "$PLUGIN_DIR/tests/unit/validation.php"
"$WP_CLI" --path="$WP_PATH" eval 'if (!get_option("yoohwsm_disposable_tests")) { WP_CLI::error("Opt in on a disposable database: wp option update yoohwsm_disposable_tests 1"); }'
python3 "$PLUGIN_DIR/tests/prefix-upgrade.py"
for test in inventory.php inventory-queries.php; do
  "$WP_CLI" --path="$WP_PATH" eval-file "$PLUGIN_DIR/tests/integration/$test"
done
python3 "$PLUGIN_DIR/tests/concurrency.py"
python3 "$PLUGIN_DIR/tests/http-boundaries.py"
