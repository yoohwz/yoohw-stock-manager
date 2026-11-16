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

## Implemented commands and boundary coverage

```sh
wp option update ysm_disposable_tests 1 --path=/disposable/wordpress
YSM_TEST_WP_PATH=/disposable/wordpress tests/run-stock-manager-suite.sh
wp --path=/disposable/wordpress eval-file tests/smoke/ysm-smoke.php
python3 bin/build-package.py /tmp/ysm-review.zip
# Extract outside source, then run Plugin Check 2.2.0 on that directory:
wp plugin check /tmp/ysm-dist/yoohw-stock-manager --format=json > /tmp/plugin-check.txt
python3 bin/check-plugin-check.py /tmp/plugin-check.txt
```

The suite includes strict input/CSV units, functional Woo tests, independent PHP
process races and a real HTTP admin-post regression. HTTP tests create their own users
and products, temporarily point the disposable site's URL to a loopback PHP server,
and restore URLs/remove only their fixtures in finally blocks. Do not run concurrent
lifecycle/activation tests on the same site while this suite runs.

HTTP coverage includes mixed bulk success/failure, replay skipped counts, the
WP_List_Table nonce collision regression, absent nonce, subscriber-denied mutation,
history/export permissions, filtered export and CSV formula neutralization.
Stock tests inject history, lookup and status SQL failures and reject nested transactions.

Plugin Check warnings for direct database reads/writes and assembled SQL are reviewed
against allowlists/prepared values. Live balances, row locks and cursor reads deliberately
bypass object caches. Table/column/sort identifiers and operators are internal allowlists;
request values are prepared. Warnings are reported, not treated as a clean warning-free
scan. The CI parser fails any packaged ERROR or unrecognized/truncated result format.

## Local UI and lifecycle evidence

Actual browser comparison used the read-only Premium 2.0.1 reference on the disposable
site, with the same license seam as Premium's own CI. Free screenshots are under ignored
`artifacts/ui/`: Inventory, History, single form/result, bulk result, validation error,
empty state, responsive Inventory/History and help. Premium Inventory/Movements reference
screenshots are alongside them. No Human store records were used.

Confirmed Free UI: search/filter/table, two-product bulk adjustment, single set, invalid
negative increase, History records, actual CSV download and 390px layout. Tables use
keyboard-focusable horizontal scrolling rather than squeezing numeric columns. The
broad Premium workspace remains recognizable; incoming/reserved/available/location/
advanced actions are removed. A new stock ledger or design system was not introduced.

Confirmed actual coexistence: Premium activation preserves existing quantities and Free
history; Free's service denies writes, its observer is absent, and a Woo API change is
not also recorded in Free. Deactivating Premium restores Free's observer. Deactivating
and reactivating Free preserves stock/history. A process with WooCommerce skipped boots
without the Free stock runtime or a fatal error. Local WP 7.1.4 / WC 10.0.4 / PHP 8.4
also exercised the suite, supporting the readme's Tested up to 7.1 declaration.

## Full feature regression coverage

`tests/integration/inventory-queries.php` creates isolated product/category fixtures to
verify name/SKU/full-ID searches, inherited versus own-stock variation ownership,
numeric-leading SKUs, trashed children, category descendants, status/threshold views,
sorting, page boundaries and inventory CSV across the 250-row cursor boundary. It also
checks history product/source/reason/date filters at site-timezone day boundaries.

HTTP regression checks native form ownership: the table/pagination GET form owns no
adjustment fields, while row checkboxes explicitly belong to the separate bulk POST
form. Enter in Current Page must only navigate; filled bulk fields and selected rows
must not change stock/history until Apply adjustment is submitted. Verify this browser
flow with more than 25 products and verify Select all still submits the intended rows.
The HTTP suite also checks shared-parent bulk deduplication/replay and a user who can
manage WooCommerce but cannot edit products. Order/refund tests cover shared-parent and
own-stock variations in addition to simple products.


Inventory follows the Premium selection flow: select one or more rows and submit
through the bulk bar. There is no Actions column or separate per-product adjustment
panel. Verify one-row and multiple-row selection through the same POST form, while
pagination remains a separate read-only GET form.
