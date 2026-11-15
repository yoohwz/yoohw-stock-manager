#!/usr/bin/env bash
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${YSM_TEST_WP_PATH:?Set YSM_TEST_WP_PATH to a disposable WordPress installation}"
PHP_BIN="${YSM_TEST_PHP_BIN:-php}"
WP_CLI="${YSM_TEST_WP_CLI:-wp}"
"$PHP_BIN" "$PLUGIN_DIR/tests/unit/validation.php"
"$WP_CLI" --path="$WP_PATH" eval 'if (!get_option("ysm_disposable_tests")) { WP_CLI::error("Opt in on a disposable database: wp option update ysm_disposable_tests 1"); }'
for test in inventory.php; do
  "$WP_CLI" --path="$WP_PATH" eval-file "$PLUGIN_DIR/tests/integration/$test"
done
python3 "$PLUGIN_DIR/tests/concurrency.py"
