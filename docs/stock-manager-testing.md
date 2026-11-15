# Free Stock Manager verification

Workflow-only checks (no WordPress required):

```sh
python3 -m unittest discover -s tests/ci -p 'test_*.py' -v
bash -n bin/validate-ci-gate.sh
```

Before runtime exists PHP/unit/integration/browser/Plugin Check are NOT RUN.
Phase B supplies `tests/unit/validation.php`, `tests/run-stock-manager-suite.sh`
and `tests/smoke/ysm-smoke.php` for the hosted workflow. No placeholder test passes.
Use `YSM_TEST_WP_PATH` pointing to a disposable installed WordPress/WooCommerce site.
Never run fixture creation/deletion on the shared Human store.

Cover simple/variation/shared-parent stock, decimal quantities and backorders,
set/increase/decrease, replay/concurrency, capability/nonce boundaries, order
reduction/restoration and refund restocking without duplicate history. Verify CSV
filter scope, formula neutralization, chunking, activation/dependency behavior,
retained history and Premium coexistence. Compare running Inventory and History
screens to Premium; source comparison alone is not browser evidence.

The inherited matrix spans PHP 7.4–8.4, WP 6.3.5–7.0.2 and WC 8.0.3–10.9.4,
legacy and HPOS. CI verifies effective order storage. MySQL 8 CI does not prove 5.7.
Run Plugin Check separately and record the exact candidate and limitations.
