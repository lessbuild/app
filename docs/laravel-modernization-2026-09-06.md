# Laravel modernization — 2026-09-06

This refactor preserves the configuration-as-code feature completed at `b6ee620`, existing routes, authorization boundaries, persisted status strings and display APIs. It upgrades the framework and assets, extracts presentation/query responsibilities, and improves explicit types and database access. It does not deploy the application or apply its pending migrations.

## Implementation

| Area | Result |
| --- | --- |
| Dependencies | Laravel 13.30.1, Livewire 4.4.3, PHP 8.5.10, PHPUnit 13.3.2, phpseclib 4.0.1, Symfony YAML 8.1.6; Tailwind 4.3.3 and Vite 8.2.2. Lockfiles and runtime requirements are updated. |
| Route model binding | Environment variables, command history, notifications, personal tokens and report status use typed models. Nested environment children use scoped binding. Explicit parent/morph ownership checks preserve 404 responses for foreign records. |
| Controllers | Stateful signed provisioning/deployment callback closures and public home/pricing/OpenAPI handlers have dedicated controllers with explicit request/response contracts. Route names and HTTP response semantics remain compatible. |
| Query scopes | All 14 existing local scopes moved into six model-specific traits under `app/Models/Scopes`, preserving their public query APIs. |
| Presenters | Separate duration, build, server, sign-in and public status-component presenters own display formatting. Existing model methods/accessors delegate to them so views and other callers remain compatible. |
| Events and jobs | Cashier's completed-webhook event maps to `SyncSeatsAfterBillingWebhook` in `EventServiceProvider`; the listener resolves its workspace and dispatches the existing seat-reconciliation job. Existing queues, retries, observers and lifecycle actions remain in use. |
| Enums | `BuildStatus`, `ServerCommandStatus` and `SignInMethod` provide typed domain values, classification and guards. Model constants, database values and serialized output retain their existing strings, including unknown historical values. |
| Native types | Application methods have explicit native parameter and return types, including `mixed` where external inputs or inherited contracts require it. Constructors/destructors are excluded from return-type requirements. No `strict_types` declaration was added. |
| Documentation | All model/enum methods, extracted scopes/presenters/listener and configuration-controller actions describe input/output contracts; model relationship generics are supplied throughout. Existing comments are retained or moved with the code. This does not claim every legacy application method has a PHPDoc block. |
| Shared helpers | CSV spreadsheet-formula escaping is centralized across 13 export controllers. Public-IP validation is shared across server import, SSO endpoint and alert-webhook validation. |
| Formatting and CI | Pint preserves existing PHPDoc tags and checks the repository. The CI template installs locked dependencies, checks formatting and advisories, builds assets and runs the PHP/browser suites; activation is pending GitHub workflow permission. |

## Correctness and query efficiency

- `latestSuccessfulBuild` now selects the greatest successful build ID inside its one-of-many constraint. A newer failed or pending attempt no longer hides the last successful release. Eager loading remains bounded across multiple repositories.
- Receipt refresh eagerly loads related builds and projects retry existence in its operation query. Owned and shared receipt histories no longer perform two additional reads per operation. Regression coverage compares two and twelve operations, retaining retry and aggregate status behavior under the existing locks.
- Public status components aggregate recent/healthy check counts in the website query. Six components require one health-aggregation query; tests verify the inclusive 30-day boundary, per-website counts, ordering, missing history and unpublished-page rejection.
- Server command history derives all six filtered metrics in one conditional aggregate instead of six independent count queries. Existing owner, status/date filter and empty-result tests verify the result.
- DigitalOcean's legacy adapter now declares and validates decoded array responses and uses the standard `InvalidArgumentException`. Malformed success responses raise a controlled provider error without exposing their body.
- Server and website lifecycle callbacks reload and check the current attempt under a transaction lock. Stale route-bound models cannot overwrite a retried or completed attempt, and progress stays monotonic. Preview bookkeeping is synchronous and placement cleanup is dispatched after commit. Validated numeric-string progress is normalized for server, website and build callbacks so real form-encoded final stages complete correctly.
- Laravel 13 session authentication is configured to send revoked browser sessions to login while preserving intended URLs; JSON clients retain a 401 response. The CSV helper preserves the admin access export's distinct empty-string and single-line formatting contract.

## Dependency and rollout boundaries

The latest stable versions permitted by the upstream dependency graph are locked. Six PHP packages and several npm transitive packages cannot use their newest releases under current upstream constraints. No unsupported aliases, vendor edits or forced major-version overrides are used. Exact versions and blockers are in [the PHP upgrade record](php-dependency-upgrade-2026-09-06.md) and [the frontend verification record](verification/frontend-modernization-2026-09-06.md).

PHP 8.5 must be available to FPM, queue workers and the scheduler before deploying this checkout. The task-local runtime used for tests did not replace the system PHP or restart any services. JSON session serialization requires renewed sign-in at rollout unless the documented temporary PHP-serialization override is used. Livewire 4 uses hashed asset/update paths. Tailwind 4 raises the browser baseline; see the frontend record. The six configuration migrations remain a separate rollout requirement.

All database verification uses isolated test databases. Live paid-provider actions, OAuth/Stripe integration accounts, FPM deployment and real configuration operations have not been exercised by this refactor.

## Verified checkpoint

The complete PHP suite passed **1,165 tests / 10,643 assertions** across all **205 test files**, with zero failures, errors, skips, warnings, risky tests or deprecations. Four independent PHP 8.5 processes each used the repository's in-memory SQLite test configuration and separate PHPUnit run-history directories; every Unit/Feature file was included once. All four processes exited zero. Each process used at most 109 MB as reported by PHPUnit. Temporary evidence is `/tmp/buildpusher-modernization-verified-{0,1,2,3}.{log,xml}`.

The final runs enabled `--fail-on-warning --fail-on-risky --fail-on-deprecation --fail-on-phpunit-deprecation` and used PHPUnit 13's `--do-not-record-test-run-history`. The earlier run exposed the distinct admin-export formatting contract and Laravel 13 revoked-session redirect change; both were fixed and the entire suite rerun. The test-runner's cache paths were restored to those required by the repository's isolation tests.

Additional verification:

- **48 rendered browser layouts** (six screens, four widths, two themes), plus 35 UI tests / 725 assertions. These overlap the complete PHP suite and must not be added to its totals.
- Clean `npm ci`, a production build with matching browser-tested artifact hashes, and an npm audit with zero vulnerabilities.
- Composer validation, installed-platform requirements, optimized autoload discovery, production-install dry run and security audit passed. No security advisories or abandoned PHP packages were reported.
- Full-repository `pint --test` passed; subsequent session-provider additions passed the same formatter configuration. `git diff --check` passed.
- `artisan route:cache` passed using a separate `/tmp` cache file, leaving the application's route cache unchanged.
- Native signature audit: 423 app PHP files / 1,491 class methods, with zero missing native parameter/return types; constructors/destructors are excluded from return requirements. No `strict_types` declarations were added.
- Independent comment-token/method audit found no unexplained loss among 304 original comments in 238 changed files at the audit checkpoint; 14 relocated scope methods were accounted for in the new traits. Later fixes preserved the code they moved.

Reproduce the application suite with PHP 8.5 and `php artisan test`; use the committed [CI template](ci/verify.yml) and frontend verification record for the complete install/build/browser sequence. GitHub rejected publication of an active workflow because the connected OAuth credential lacks `workflow` scope. The definition is preserved as an inactive template so the verified application can be published. The original active-workflow commit is also retained locally on `local/modernization-with-ci-20260906`. See [activation instructions](ci/README.md). No GitHub Actions run is claimed. Screenshots and temporary logs remain local review artifacts, not deployment evidence.
