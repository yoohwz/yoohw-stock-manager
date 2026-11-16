# Selected Premium reference and Free boundaries

Reference: yoohwz/wc-extented-stock-status@d21f40a595d4203aa789355c4d43bd4f5dd226fd (2.0.1).
The shared local Premium checkout is dirty and was not used as the candidate source.

Workflow classifier, negative gates, matrix and CI contracts are selected/adapted from
Premium. UI reuses selected CSS rules (tabs, metric cards, filters, bulk form, pills),
WP_List_Table markup/pagination patterns, product detail rows and movement presentation.
Stock operation validation, operation identities, movement fields and streaming export
patterns informed the smaller WooCommerce-only implementation. History tracking uses
paired Woo hooks with call-bound source attribution, rather than the Premium pending
ledger/location mechanism or legacy restore fallback. No Premium business module,
image/font bundle, proprietary header/license gate/updater is distributed.

The project owner's instruction authorizes extracting the selected Yo Ohw Studio source
for the GPL-compatible Free product. The selected derivative code and new Free code are
licensed GPL-2.0-or-later. WordPress/WooCommerce remain separately installed dependencies;
no third-party commercial assets are copied. `license.txt` contains the GPL v2 text.

Free mutations use the CPT WooCommerce stock storage contract and InnoDB transactions;
custom product data stores are not certified. Transaction rollback covers database stock,
lookup and Free history, not third-party hook side effects or external emails/webhooks.
Observer hooks do not serialize external concurrent writers; before/after is observed
state, not a reconstructable global ledger. Failures recording external changes create
an admin warning; external Woo operations are never rolled back by this observer.

Premium detection happens after plugins load using its class/function identity. Free
keeps inventory and historical CSV/read access but rejects service writes and does not
register its tracker. This prevents dual movement processing. Transition keeps quantities
and Free history; it does not merge two ledgers or retroactively fill paused intervals.
