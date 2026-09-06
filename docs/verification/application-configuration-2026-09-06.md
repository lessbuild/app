# Configuration-as-code local verification

Verified in the existing worktree on 2026-09-06. All preexisting tracked and untracked work was preserved. This is local feature verification, not a deployment or provider release drill.

## Scope completed

- Version 2 parsing, logical bindings, read-only planning, expiring reviews, explicit adoption, ownership, transactional apply and portable fixture topology.
- Explicit child and whole-environment removal with complete plans, dependency/ownership guards, no-op retries, rollback and preserved remote targets/build history.
- Encrypted variable/resource/base-environment snapshots; production YAML dependency; pre-expansion parser limits and runtime validation.
- Durable deployment intents, semantic deduplication, queue-delivery recovery, explicit remote retry, pending cancellation and current receipts available to workspace managers.
- Rechecks at local apply, build reservation and remote execution; configuration origin survives receipt deletion and prevents ordinary execution fallback. Deleted requesters preserve receipts while blocking further execution.
- Equivalent web/API workflows, published OpenAPI schemas and operator recovery/rollout documentation.

## Verification record

The final complete regression run passed **1,060 tests / 10,179 assertions**, with **zero failures, errors or skipped tests**, covering all 195 test files exactly once. This includes the final requester/deletion safeguards and management recovery access. Earlier verification exposed an outdated single-menu assertion and a permission-test fixture problem; both were corrected before the final run.

| Independent process | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| 0 | 255 | 2,620 | Passed |
| 1 | 229 | 1,941 | Passed |
| 2 | 336 | 2,971 | Passed |
| 3 | 240 | 2,647 | Passed |

The complete suite runs all PHP test files under `tests/Unit` and `tests/Feature`, divided into four independent PHPUnit processes. The union of those file lists was checked against the repository's recursive test-file list, with no omissions or duplicates. Each inherits the repository's testing environment and in-memory SQLite isolation. Temporary configuration and migration race tests create their own uniquely named SQLite files and remove them afterward. No configured application database is migrated by these tests. The ordinary single-process entrypoint remains `php artisan test`; final JUnit reports were saved under `/tmp/buildpusher-verified-*-results.xml` (temporary artifacts).

Direct concurrency evidence holds one transaction open while another process begins, covering duplicate apply, competing reviews, duplicate explicit retry, deployment-start/removal interaction and concurrent manual-child insertion. Migration evidence covers populated pre-feature application records, an existing operation upgraded to retry support, all six migration downs/upgrades and refusal to discard retry history.

`composer validate --no-check-publish` and `composer install --no-dev --dry-run --no-scripts` pass. Symfony YAML is retained in production dependencies. The Vite production build and `git diff --check` pass. Focused PHP formatting checks pass.

Rendered removal-review checks use the real disposable HTTP-test response with built CSS and Livewire at 320, 390, 768 and 1440 pixels, in light/dark modes. All eight layouts have no horizontal overflow, show the explicit remote-preservation/charges warning and provide an unobstructed apply button after scrolling. No JavaScript errors were recorded. HTTP tests independently exercise the actual review/apply/retry/cancel requests.

An additional **16 rendered layouts** cover configuration upload/history and the applied receipt at the same widths/themes. History links are present, cancel controls are visible and unobstructed after scrolling, Livewire loads, private commands are absent and no horizontal overflow or JavaScript errors occur. These use copied disposable PHPUnit fixtures, not real accounts. Temporary screenshots/results are `/tmp/buildpusher-configuration-{create,receipt}-{390,1440}.png` and `/tmp/buildpusher-configuration-recovery-browser-results.json`. Together with removal review, **24 rendered layouts pass**.

## Operational boundaries

- The six configuration migrations remain pending in the working application's database. No real operations were processed, providers contacted or paid infrastructure created during implementation.
- No commit, push or deployment was made. Work remains in the existing worktree.
- Database concurrency was verified on SQLite. Rehearse on the actual deployment database engine before rollout; MySQL/PostgreSQL behavior is not certified by the SQLite tests.
- Live-provider deployment, restored-data verification and provider-side cleanup remain the deferred release gates from the roadmap.
- Environment/resource removal preserves remote workloads and data. It neither releases a Valkey container's occupied port nor reduces provider charges.

See [the operator contract](../application-configuration.md) for syntax, permission rules, exact recovery actions and rollback guidance. The next roadmap feature was not started.
