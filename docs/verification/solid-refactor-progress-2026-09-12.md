# BuildPusher SOLID refactoring progress ledger

## Phase 0 — isolated baseline

Date: 2026-09-12 UTC

Source checkout at start: `/root/Documents/Codex/2026-08-30/clone-my-repo-work-on-it/deployer`

- Starting source commit: `a137739` (`main`, matching `origin/main`).
- The source checkout had no tracked changes. Its only existing untracked change was `docs/solid-refactor-plan-2026-09-12.md`; that plan was copied into this worktree and remains untouched in the source checkout.
- Isolated worktree: `/root/Documents/Codex/2026-09-12/buildpusher-solid-and-laravel-refactoring-plan`.
- Branch: `refactor/solid-laravel-20260912`.
- No applicable project `AGENTS.md` exists; the only discovered file was under `vendor/` and was not applicable.
- Locked Composer dependencies were installed with PHP `/root/.local/share/buildpusher/php-8.5.10/bin/php` and an isolated Composer cache. `npm ci --no-audit --no-fund` completed successfully.
- The isolated `.env` uses `APP_URL=http://127.0.0.1:8092`, SQLite at `storage/database/refactor.sqlite`, file cache/session storage, an isolated session cookie, absolute cache paths under this worktree, and isolated backup/repository directories. A new local application key was generated; its value is not recorded.
- Runtime path assertions were run before Artisan: the resolved database, cache, session, filesystem, queue and Laravel cache paths all pointed into this worktree. Neither the live checkout database path nor the acceptance-drill database path existed.
- All 119 migrations completed successfully. `Database\Seeders\DemoSeeder` populated only the isolated SQLite database for browser checks.
- The isolated Laravel server serves successfully at `http://127.0.0.1:8092` when started with `--no-reload`; Laravel's reload-mode `serve` process intentionally passes only selected environment variables to its child, which omitted the isolated key and database settings.

### Baseline commands and results

| Check | Result |
| --- | --- |
| `php artisan test --fail-on-warning --fail-on-risky --fail-on-deprecation --fail-on-phpunit-deprecation --do-not-record-test-run-history` | Exit 2; 1,132 passed, 53 failed, 10,358 assertions across 1,185 tests. Failures clustered in rate-limited account/access/recipe/session/security flows and the testing-environment isolation test; no source changes had been made in this worktree. |
| `php vendor/bin/pint --test` | Passed. |
| `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npm run test:assets` | Passed: 9 browser fixture tests; isolated Vite build completed. |
| `BROWSER_BASE_URL=http://127.0.0.1:8092 BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npx playwright test tests/Browser/accessibility.spec.js` | 2 passed, 1 failed. The existing tablet check expects a `Search and navigate` button at 768px although that control is not rendered at that breakpoint. |
| Combined `npx playwright test` attempt against the isolated server | Not a valid clean baseline: the command omitted `BROWSER_PHP_BINARY`, so its asset fixture invoked system PHP 8.3; the visual audit then waited on an absent mobile `Settings` link and was interrupted. |

The full PHP suite and focused browser failures are recorded as pre-refactor baseline findings. They are not silently attributed to the refactor. The separate live acceptance drill and paid/cloud acceptance remain outstanding and were not modified or reused.

## Next task

Finish the Phase 1 configuration-as-code map, run the focused configuration regression set, and document the smallest justified extraction from `ApplicationConfigurationReconciler::apply()` before editing production code.
