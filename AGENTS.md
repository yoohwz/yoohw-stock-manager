# YoOhw Stock Desk for WooCommerce

Read `docs/workflow.md` and `docs/stock-manager-testing.md` before changes.
Human-facing communication is Vietnamese; source and documentation use English.

One Codex Main owns discovery, design, implementation, testing, independent review,
corrections and PR maintenance. Human owns scope/product decisions, merge, release,
publication, deployment and rollback. ChatGPT approval is not required.

Inspect Git and preserve unrelated work. Use `codex/<scope>` and normally one Draft
PR. Never force-push, weaken checks, or use Human/production data as disposable fixtures.
Use disposable WordPress databases. Keep Premium reference checkouts read-only and
outside this public repository; copy only explicitly selected Free components.

Use Fast for isolated docs/tooling/presentation changes; Controlled for stock, data,
schema, auth, concurrency, recovery and compatibility. Record a proportional plan in
the existing PR before Controlled runtime work: invariants, scenarios, risks,
validation and complexity budget. No extra approval gate, ledger or task allocator.
Fresh independent architecture critique precedes safety-critical implementation.
Fresh independent implementation review uses a separate clean checkout and exact SHA;
record findings and correction deltas on the PR. Delegate bounded tasks only where useful.

WooCommerce owns stock. Resolve parent-managed stock once. Protect against replay,
lost updates, unauthorized writes and duplicate history. Do not import Premium locations,
batches, reservations, licensing/updater or advanced business modules. Keep Free history
on deactivation, uninstall and transition to Premium. All shipped components must have
GPL-compatible usage rights. No telemetry, account or license requirement.

Follow Iteration/Final CI in `docs/workflow.md`. Missing evidence is NOT RUN with a
reason. Ready-for-merge requires exact-head Final CI and required independent review.
Do not merge, tag, release, publish, deploy or rollback without Human authority.
