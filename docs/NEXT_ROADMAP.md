# Next development sequence

Reviewed 2026-09-06. Work one item at a time; passing a narrow test does not establish completion of a whole workflow. The original roadmap checkmarks describe existing implementation, not demonstrated production parity.

## Competitor comparison

These are comparisons of documented capabilities against inspected source, not hands-on competitor benchmarks or claims about pricing.

| Reference | Documented capability | BuildPusher evidence and opportunity |
| --- | --- | --- |
| [Render Blueprints](https://render.com/docs/blueprint-spec) | Declarative service and database configuration | `WorkflowConfiguration` updates schedules, scaling and processes on existing environments. Expand to reproducible application topology with validation and a reviewable change plan. |
| [Render previews](https://render.com/docs/preview-environments) | PR environments instantiate Blueprint services and datastores, support initialization and automatic cleanup; existing data is not copied | `PreviewDeploymentLifecycle` provisions a website/repository preview. Verify and extend isolation and dependent-resource lifecycle before claiming full-stack preview parity. |
| [Coolify service catalog](https://coolify.io/docs/services/overview) | Broad catalog of deployable services | `config/application-templates.php` supplies framework presets. A curated service catalog needs persistent storage, dependency configuration, upgrades and recovery support. |
| [Laravel Forge](https://laravel.com/forge) | Laravel VPS offers shared interactive browser terminals | BuildPusher has queued command execution and retained output. Interactive sessions require additional lifecycle, access and disconnect handling. |

## Ordered implementation backlog

1. **Finish acceptance-audit correctness locally.** The pending changes improve chronological checks but are not exhaustive. Verify repository/website/server consistency and rollback artifact identity; search complete candidate chains rather than selecting the first backup or newest rollback prematurely. A mutable destination verification timestamp must not invalidate earlier successful backup evidence. Reject invalid calendar dates. Add targeted regression cases. Keep restored-data validation and provider cleanup explicitly separate from facts the database audit actually proves. Completion: valid repeat drills pass, adversarial mixed evidence fails, output states its limits.
2. **Application configuration as code.** Version a schema for services, resources, runtime and secret references; provide validate/plan/apply with ownership checks, predictable removal semantics and idempotent reapplication. Completion: a fixture application can be recreated with the same non-secret configuration, changes can be reviewed before application, and failed application is recoverable.
3. **Complete preview environments.** Build on item 2 for dependent resources, explicit initialization, secret isolation, fork handling, quotas and cleanup retries. Completion: open/update/close/expiry cycles work for a multi-service fixture and leave no orphaned resources.
4. **Curated service templates.** Start with a small supported catalog, including persistent storage, health probes, pinned versions, upgrades and restore instructions. Completion: every supported template has installation and recovery evidence; source tests alone do not imply live-provider verification.
5. **Interactive troubleshooting.** Add scoped terminal sessions with short lifetimes, resize/disconnect handling and authorization revalidation. Completion: unauthorized connections fail, revoked sessions close and abandoned sessions do not retain remote processes.

This ordering is an engineering judgment: close audit correctness first, then build configuration foundations before multiplying deployment options. It does not authorize paid infrastructure creation.

## Deferred release gates

- Independent monitoring endpoints and live heartbeat/status verification.
- An authorized disposable cloud drill with valid restricted credentials, a cost limit, restored-data verification and provider-side cleanup confirmation.
- Production mail delivery, GitHub App configuration and approved billing activation as recorded in the original roadmap.

Live tests remain required before release. Deferral does not count as passing them.
