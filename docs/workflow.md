# Free Stock Desk engineering workflow

Adapted from Premium `d21f40a595d4203aa789355c4d43bd4f5dd226fd`.

Human goal → Codex plan → implementation → tests → proportional independent review
→ corrections → exact-head Final CI → Human merge decision.

Use Git/PR for candidate and scope, CI for checks, and PR discussion/reviews for
findings. One Draft PR normally spans workflow and runtime in separate commits.
Fast covers bounded docs/tooling/UI-only work. Controlled covers stock, persistent
data/schema, authorization, concurrency and recovery. Record invariants, scenarios,
risks, validation and a small complexity budget in the existing PR. Fresh independent
critique precedes new safety-critical architecture; independent implementation review
is SHA-bound in a clean checkout. Advisory preferences do not block completion.

## CI

`tests/compatibility-matrix.json` is the sole environment matrix. Draft PRs run
repository contracts, applicable PHP/JS checks and one PHP 8.2 HPOS environment.
Ready PRs run applicable checks across all matrix environments. Final control-plane
changes exercise the complete graph. PR jobs check the actual head SHA. Feature-branch
pushes do not also run CI. Only main pushes, PR lifecycle and manual dispatch run CI.
Drafts emit Iteration Gate; Final emits CI Gate. Required failures, cancellations,
missing or unexpected skipped results fail closed. Do not change branch protection.

Before runtime exists, run routing/negative-gate/repository contracts and shell/Python
syntax locally. Plugin checks are NOT RUN; do not invent placeholder successes or add
an existence-based green bypass. Complete runtime-dependent CI commands in Phase B.
WordPress.org readiness requires Plugin Check, GPL-compatible licensing, translated
UI, valid metadata/readme, safe packaging and no proprietary updater. No publisher.

## Completion

Convert to ready and wait for applicable Final CI on the unchanged head. Record fresh
review SHA, corrections, checks and evidence limits in the PR. Changed head invalidates
readiness; verify affected deltas. Only claim READY_FOR_HUMAN_MERGE once complete.
Missing evidence remains unfinished engineering. Merge and release remain Human actions.
