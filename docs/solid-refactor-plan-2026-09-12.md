# BuildPusher SOLID refactoring plan for Luna Ultra

## Objective and baseline

Refactor incrementally so business operations have clear owners, HTTP controllers coordinate requests, and external integrations remain replaceable and testable. Preserve observable behavior, security, data, and deployment guarantees. This is an implementation plan, not permission to deploy or run cloud tests as part of refactoring.

Inspected on 2026-09-12 at `a137739`; the worktree was clean before this document was added. Read `docs/CHAT_HANDOFF.md` first on every resumed session: its latest checkpoints supersede historical rollout statements in older documents.

The application already uses Laravel 13, PHP 8.5, Livewire 4, actions, data objects, provider contracts, policies, query scopes, presenters and enums. Earlier modernization added native types, method documentation and concurrency protection. Do not repeat that work or upgrade dependencies to demonstrate modernization.

Observed candidates include `RecipeReportsController` (823 lines), `WebsitesController` (720), `BuildsController` (553), `RepositoriesController` (546) and `ProviderController` (498). Size indicates where to inspect, not a target to optimize mechanically. Configuration services are already separated; improve specific responsibilities rather than replacing the subsystem.

## Working rules

- This checkout serves the live application. Implement in a new branch and separate worktree with independent dependencies, assets, storage, cache paths, application key and test database. Do not copy production credentials. Do not reuse or alter `/root/.local/share/buildpusher/drill-20260908/app`, which belongs to the pending acceptance drill.
- Preserve existing changes and comments. Inspect status and diffs before editing. Never reset or clean work belonging to another task.
- Use `/root/.local/share/buildpusher/php-8.5.10/bin/php`; system PHP is incompatible with the locked dependencies. Install locked versions, not `composer update` or forced dependency overrides.
- Keep native parameter/return types and useful PHPDoc, including array shapes and relationship generics. Do not add `declare(strict_types=1)`.
- Preserve route names, response structures, status codes, redirects, flash messages, validation keys, YAML format, persisted values and job payload compatibility. No schema changes by default; justify any unavoidable migration separately with compatibility and rollback analysis.
- Complete and verify one slice before starting another. Do not combine behavior changes with structural moves. If a bug is found, isolate its regression and fix in a separate commit.
- No production migrations, cache changes, worker restarts, billing changes or paid API mutations during this refactor. Deployment and cloud acceptance are separate gates.

## Design rules: SOLID in this application

| Principle | Concrete application |
| --- | --- |
| Single responsibility | Separate a controller's request handling from reusable business operations, inventory queries, reporting metrics and CSV rendering. Give each configuration collaborator one coherent responsibility. |
| Open/closed | Reuse `ServerProvider` and `ServerProviderResolver` for cloud implementations. Add a strategy only where multiple variants already need independent behavior; avoid speculative plugin frameworks. |
| Liskov substitution | Verify adapters against shared behavioral expectations, including absent-resource deletion, malformed responses, identifier types and sanitized failures. Do not normalize away documented provider differences. |
| Interface segregation | Introduce smaller capability contracts only when real consumers need different subsets. Do not split every existing interface by default. |
| Dependency inversion | Inject collaborators at meaningful boundaries. Keep Laravel's container at composition boundaries. Do not introduce an interface for every action or wrap Eloquent in generic repositories. |

Follow existing `app/Actions`, `app/Data`, `app/Contracts`, `app/Policies`, `app/Models/Scopes` and `app/Presenters` conventions. Proposed class names below are provisional; check for existing equivalents first. Use Form Requests for HTTP validation, policies for access decisions, and explicit business guards inside operations invoked by jobs or commands. Simple CRUD can remain in controllers. Use events for secondary effects only where timing and delivery semantics are preserved.

## Phase 0 — Isolate and establish evidence

1. Read the current handoff, `docs/application-configuration.md`, `docs/NEXT_ROADMAP.md`, modernization acceptance records and `phpunit.xml`. Inspect applicable `AGENTS.md` files and current Git state.
2. Create the isolated refactor checkout described above. Verify resolved application, database and cache paths before running Artisan. Never assume `APP_ENV=testing` alone protects the live database.
3. Map HTTP/command/job entry points, authorization, transaction boundaries and side effects for configuration operations and provider management. Record a short dependency map and proposed extractions.
4. Run the existing Unit/Feature suite, Pint and relevant browser tests. Record the baseline commit, exact commands, counts and failures. Historical passing counts are context, not current evidence.
5. If the full suite exceeds execution limits, partition all Unit/Feature files into bounded batches; verify every file is included exactly once. Avoid resource-heavy parallel runs on this host.

**Exit gate:** isolated runtime proven, baseline recorded, any existing failures distinguished from new regressions. No implementation before this gate.

## Phase 1 — Configuration-as-code first

Finish this domain before changing other features. The live configuration acceptance drill remains incomplete because source-control credentials/repository prerequisites were missing at the latest checkpoint. Structural refactoring can be tested locally; do not declare end-to-end feature acceptance complete without the separate drill.

### 1A. Characterize the current contract

Trace `ApplicationConfigurationController`, `ApplicationConfigurationReviews`, `ApplicationConfigurationPlanner`, `ApplicationConfigurationReconciler`, `ApplicationConfigurationTransaction`, `ApplicationConfigurationDelivery` and `ApplicationConfigurationExecution` through their callers.

Document: immutable review inputs and identity checks; ownership and entitlement checks; environment-removal safety; local reconciliation versus remote execution; operation states; cancellation/retry rules; leases and attempt guards. Add tests only for meaningful uncovered behavior.

### 1B. Extract cohesive reconciliation responsibilities

`ApplicationConfigurationReconciler::apply()` currently combines environment persistence, process/resource reconciliation, resource-variable construction, ownership recording and durable deployment intents. Extract one cohesive responsibility at a time, keeping the public service entry point stable.

Candidate collaborators: environment reconciliation and resource-configuration construction. Continue using existing `ApplicationConfigurationVariables`, bindings and transaction services. Do not move every loop into a trivial class. Keep all local writes under the existing transaction and locking policy; preserve secret-version revalidation, ownership checks, intent identity and no-op behavior.

Introduce immutable objects under `app/Data` only for stable structures shared across meaningful boundaries. Start with one repeatedly passed structure if it improves correctness. Keep explicit array serialization at YAML, JSON and persistence boundaries; do not replace every array or change digest generation.

### 1C. Make delivery outcomes clearer without changing execution

Inspect `ApplicationConfigurationDelivery`'s `int|false` lease result and `ApplicationConfigurationExecution::claim()`'s nullable boolean semantics. If callers are clearer with a named outcome or enum, migrate all callers and tests together. Document every state before introducing a type; preserve legacy persisted strings and unknown-value handling.

Keep enqueueing outside transactions because the sync queue driver can execute immediately. Preserve project locks, lease expiry, guarded attempt updates, queued-to-deploying compare-and-set and stale callback protection. Do not replace atomic operations with an in-memory state machine.

**Tests:** all `ApplicationConfiguration*Test.php`, `ConfigurationOperation*Test.php` and `ConfigurationOwnershipTest.php`; emphasize concurrency, transaction, environment removal, removal workflow, resource safety, recovery access, retry, API/OpenAPI and results-performance tests. Exercise sync and asynchronous delivery behavior with isolated/fake infrastructure.

**Exit gate:** existing configuration contracts and query bounds pass; no changed YAML/API schemas or remote side effects; documented comparison of transaction/lease behavior before and after. Preserve the outstanding live-acceptance blocker explicitly.

## Phase 2 — Provider management

1. In `ProviderController`, separate inventory and connection-history filtering/metrics into cohesive query collaborators when shared by HTML and export paths. Keep the organization-scoped relation as the starting query. Preserve pagination, sort order, date boundaries and CSV escaping.
2. Keep `ProviderRequest` responsible for HTTP input. Extract provider create/update operations only where entitlement rules, monitoring defaults and credential persistence warrant a reusable action; do not introduce a generic CRUD service.
3. Keep manual connection testing distinct from monitoring entitlement. Preserve Free-plan defaults, visible `plan` errors, success flashes, encrypted storage and exclusion of `token` from flashed input.
4. Inspect `ProviderConnectionTester`, `ProviderHealthMonitor`, `ServerProviderResolver`, `DigitalOcean` and other adapters for duplicated transport/error handling. Extract only common behavior with matching semantics. Retain the DigitalOcean droplets-based connection probe; account-read permission must not become mandatory again.
5. Add shared adapter contract tests where useful. Fake HTTP and assert expected calls, including no accidental account endpoint, no leaked response bodies and safe handling of failures. Real cloud provisioning is unnecessary for structural verification.

**Tests:** provider submission feedback, capability, connection, health monitoring, monitoring interval/failure threshold, inventory filter/export/insights and connection history/insights suites; `ServerProviderLifecycleTest` and `CloudProviderExpansionTest` when adapters change. Explicitly enable billing enforcement in entitlement scenarios.

**Exit gate:** provider POST followed by GET displays the correct outcome with the same session cookie; native selection works without JavaScript; scoped-token behavior and workspace isolation remain intact.

## Phase 3 — Reports, then remaining large controllers

Work in this order, one independently verified slice at a time:

1. **Recipe reports:** extract reusable filter/query and CSV responsibilities from `RecipeReportsController`; move cohesive report mutations into actions and request validation into Form Requests where appropriate. Preserve distinct reporter/contributor visibility, priority ordering, locking, unread notification behavior and notification timing. Run recipe report, history and notification suites plus all additional tests discovered for its routes.
2. **Websites:** inspect `WebsitesController` and reuse `app/Actions/Web` before creating anything. Separate inventory/reporting work from lifecycle orchestration. Verify placement, relocation, retry, deletion, encryption and export behavior.
3. **Builds and repositories:** reuse `app/Actions/Repository` for publish, redeploy, promotion, rollback, cancellation and webhook handling. Extract remaining query/reporting work; preserve revision attestation, approvals, signed callbacks, cancellation and idempotency. Verify affected build/deployment/webhook suites.
4. **Servers and environments:** inspect remaining controllers only after the preceding slices pass. Reuse `app/Actions/Server`; preserve provisioning attempts, ownership and environment-removal guards.

Do not target a fixed controller length. Stop extracting when the remaining code is readable request coordination or straightforward CRUD. Avoid introducing a universal report/export abstraction until multiple extracted implementations demonstrate a stable shared need.

**Exit gate per controller:** unchanged route and response contracts, authorization from every entry point, unchanged export formatting and bounded queries, relevant tests passing. Commit before proceeding.

## Phase 4 — Cross-cutting review and final verification

- Audit touched entry points for consistent validation and authorization. Policies do not replace transaction-time ownership or state revalidation.
- Check that HTTP, queue jobs and commands reuse the same business operation where appropriate. Keep required state transitions synchronous; dispatch secondary work after commit only when it preserves existing guarantees.
- Review changed relationships and queries for N+1 regressions. Extend existing query-count tests where behavior warrants it; do not add speculative caching.
- Add a small number of architecture checks only for agreed rules with concrete value, such as preventing HTTP request dependencies in extracted business actions. Do not add a large architecture framework or blanket facade ban.
- Run the complete PHP suite, full Pint, `git diff --check`, locked dependency/platform checks, asset build and browser coverage. Any schema/serialization change requires additional explicit compatibility tests.
- Build route caches only at isolated paths. Verify rendered Livewire assets are actually served by an isolated HTTP runtime: fixture-intercepted browser tests alone missed the previous production 404. Test mobile menu open/close, Escape and focus behavior and provider submission without JavaScript.
- Update `docs/CHAT_HANDOFF.md` and write a verification record containing commits, commands, results, preserved invariants and unresolved external acceptance work. Keep the inactive CI template in mind: GitHub workflow permission was previously unavailable; do not claim CI is active without checking.

**Final gate:** all slices verified, no unaccounted behavior changes, no production mutations or cloud spend, reviewable commits and rollback notes. Deployment is a separate step with its own current runtime/cache/worker checks. The prior £10 cloud-drill authorization and cleanup obligations remain attached to that separate task.

## Execution instructions for Luna Ultra

Use this prompt with the repository available:

> Implement `docs/solid-refactor-plan-2026-09-12.md` sequentially. First read `docs/CHAT_HANDOFF.md`, applicable instructions and current Git status. Start with Phase 0 and work in a new isolated checkout because the source checkout is live. Preserve all existing changes. Do not upgrade dependencies, add strict_types, change public contracts or touch production resources. Complete configuration-as-code refactoring before provider management or other controllers. Reuse existing actions, data objects, scopes, policies and contracts; introduce abstractions only for a demonstrated responsibility or substitution need. For each slice, state the intended behavior-preserving change, implement it, run the relevant tests, review the diff and commit that slice before starting the next. Keep a progress ledger with completed work, verification evidence and the exact next task. If a baseline failure or external prerequisite blocks a gate, document it honestly and continue only work independent of it. Do not mark live configuration acceptance complete without its separate acceptance evidence. Finish with complete regression verification and a concise handoff.

For each slice, record: **problem → affected entry points → proposed responsibility → preserved invariants → tests/results → commit → next task**. Prefer several small cohesive commits over a broad rewrite. The first implementation deliverable is the isolated baseline and the configuration responsibility map, followed by the smallest justified configuration extraction.
