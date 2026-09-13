# Next development sequence

Reviewed 2026-09-13. Work one item at a time; passing a narrow test does not establish completion of a whole workflow. The original roadmap checkmarks describe existing implementation, not demonstrated production parity.

## Competitor comparison

These are comparisons of documented capabilities against inspected source, not hands-on competitor benchmarks or claims about pricing.

| Reference | Documented capability | BuildPusher evidence and opportunity |
| --- | --- | --- |
| [Render Blueprints](https://render.com/docs/blueprint-spec) | Declarative service and database configuration | `WorkflowConfiguration` updates schedules, scaling and processes on existing environments. Expand to reproducible application topology with validation and a reviewable change plan. |
| [Render previews](https://render.com/docs/preview-environments) | PR environments instantiate Blueprint services and datastores, support initialization and automatic cleanup; existing data is not copied | `PreviewDeploymentLifecycle` persists a template-driven Laravel queue/scheduler/PostgreSQL/Valkey stack manifest, signed deployment callbacks record planned/provisioning/ready/failed local resource status, and an ownership-aware leased cleanup job captures exact preview identities for retryable close/expiry cleanup. Explicit initialization/secrets, atomic quotas and independent provider-readiness verification still need completion before claiming full-stack preview parity. |
| [Coolify service catalog](https://coolify.io/docs/services/overview) | Broad catalog of deployable services | `config/application-templates.php` supplies framework presets. A curated service catalog needs persistent storage, dependency configuration, upgrades and recovery support. |
| [Laravel Forge](https://laravel.com/forge) | Laravel VPS offers shared interactive browser terminals | BuildPusher has queued command execution and retained output. Interactive sessions require additional lifecycle, access and disconnect handling. |

## Ordered implementation backlog

1. **Acceptance-audit correctness locally — implemented and verified.** Repository/website/server ownership and placement checks, rollback artifact identity, alternative rollback and backup selection, strict calendar-date validation, and per-backup HTTPS evidence are implemented. Regression coverage includes valid repeat drills, mixed-workspace records, mismatched artifacts, stale and out-of-order evidence, and destination re-verification. The command explicitly separates recorded lifecycle evidence from restored-data validation, real-provider provenance and cleanup. The acceptance, managed-backup and deployment-approval suites passed together (12 tests, 124 assertions). The nullable backup-evidence migration was applied successfully; historical backups were not backfilled. Live acceptance remains deferred below.
2. **Application configuration as code — implemented and locally verified.** Version 2 provides portable topology, strict parsing/bindings, read-only plans, exact reviewed apply, ownership/adoption, explicit child/environment removal, encrypted snapshots, durable deployment intents and explicit retry/cancel recovery. Fixture portability, rollback, web/API behavior, independent-process SQLite races and migration rollout/rollback are covered. The final application suite passed **1,060 tests / 10,179 assertions**, with production build and rendered UI checks passing. See [the verification record](verification/application-configuration-2026-09-06.md) and [operator contract](application-configuration.md). The live SQLite migration rollout and database-copy rehearsal completed September 8 with existing data preserved; readiness and the configuration-delivery timer pass. See [the rollout record](verification/configuration-rollout-2026-09-08.md). The disposable live-provider deployment/restored-data/cleanup drill remains outstanding and requires provider/server and spending limits before proceeding.
3. **Complete preview environments.** Phases 3A through 3C now add the local, template-driven Laravel queue/scheduler/PostgreSQL/Valkey stack manifest, callback-backed resource readiness states and exact-identity ownership-aware retryable cleanup. Continue with explicit initialization/secrets and atomic concurrent-preview quotas. Completion: open/update/close/expiry cycles work for a multi-service fixture, enforce safe concurrency and leave no owned orphaned resources, with cloud/provider evidence recorded separately.
4. **Curated service templates.** Start with a small supported catalog, including persistent storage, health probes, pinned versions, upgrades and restore instructions. Completion: every supported template has installation and recovery evidence; source tests alone do not imply live-provider verification.
5. **Interactive troubleshooting.** Add scoped terminal sessions with short lifetimes, resize/disconnect handling and authorization revalidation. Completion: unauthorized connections fail, revoked sessions close and abandoned sessions do not retain remote processes.

This ordering is an engineering judgment: close audit correctness first, then build configuration foundations before multiplying deployment options. It does not authorize paid infrastructure creation.

## Deferred release gates

- Independent monitoring endpoints and live heartbeat/status verification.
- An authorized disposable cloud drill with valid restricted credentials, a cost limit, restored-data verification and provider-side cleanup confirmation.
- Production mail delivery, GitHub App configuration and approved billing activation as recorded in the original roadmap.

Live tests remain required before release. Deferral does not count as passing them.
