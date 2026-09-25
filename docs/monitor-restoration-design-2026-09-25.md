# Monitor restoration implementation design

Status: source implemented in the feature branch; not deployed. The additive migrations, automated regression execution and production acceptance remain pending. See [implementation and validation progress](verification/monitor-restoration-progress-2026-09-25.md).

## Preserve the native lifecycle

Monitor application archive soft-deletes the application, revokes ingestion tokens and suspends heartbeat/queue checks. It does not soft-delete its environments. The importer projects each canonical child as archived when either the parent application or the child is deleted. A successful application restore must therefore reconcile each exact mapped child from native facts: independently deleted children remain archived; present paused children remain paused; other present children become active. It must not call `restore()` on all children. Environment restore requires the native parent application to be present.

Neither operation implicitly restores the shared Core project. The user must explicitly restore that project first. Product access grants, subscriptions, paused collection, revoked credentials, disabled monitors and connection automation remain unchanged. Existing native credential/check controls remain the way to reconnect collectors after restoration.

## Separate access purposes

Keep `HistoricalExport` confined to independently authorized read-only exports. Add explicit retained-read authorization for archive index/detail views, and a separate restoration purpose. Both still require a current active canonical user, reconciled identity/workspace mappings, current workspace/project memberships and product grant, exact resource/environment mappings, and the appropriate native role. Restoration can admit retained archived resources and inactive project-product records only when the canonical project is active and unarchived. It never admits suspended/revoked/missing authority.

Archive views can link to the Core project index filtered by workspace and archived status. The current Core project detail route rejects archived projects, so it is not an appropriate restore destination. Token displays, collector actions, ingest retries and unrelated mutations retain interactive authorization.

## Durable Core request and module receipt

Use a Core product restoration contract and registry. The implemented interface is:

```php
interface ProductResourceRestorationProvider
{
    public function product(): string;
    /** @return list<string> */
    public function resourceTypes(): array;
    public function inspect(PlatformUser $actor, ResourceRestorationTarget $target, ?string $receiptRequestId = null): NativeRestorationSnapshot;
    public function apply(ResourceRestorationAttempt $attempt): NativeRestorationReceipt;
    public function withCurrentReceipt(ResourceRestorationAttempt $attempt, Closure $commit): void;
}
```

Core owns request/process/authority services. Register Monitor for application and environment resources only. Persist the Core request before touching source state: immutable actor, workspace/project/environment/resource mapping IDs, native source type/ID/workspace, expected lifecycle revision, mapping fingerprint, payload hash, idempotency key, attempt generation, lease token/expiry, next retry, receipt hash and safe error code. Reusing an idempotency key with different content fails.

1. Resolve and validate exact mappings and current authority, including the native management role. Require an explicitly active Core project. Commit the pending request.
2. Claim a fenced attempt. Monitor locks the application, affected environments and receipt in a consistent order; rechecks authority/revision; restores only the requested native row; inserts an immutable local receipt in the same Monitor transaction. An existing receipt prevents duplicate mutations but does not bypass current authority.
3. Reacquire source locks and verify the receipt still matches current source state. While those locks are held, invoke the Core projection transaction. Core rechecks authority, lease, exact mapping identities/fingerprint and active project, projects only the verified Monitor states, and completes the request.
4. Retry transient errors using capped backoff and expired-lease recovery. Block revoked authority; supersede changed source revisions. A later native rearchive must invalidate a stale restore receipt instead of allowing it to reactivate the canonical projection.

Do not hold Core locks while waiting for Monitor locks. Source locks held during the final Core projection prevent an intervening rearchive from being overwritten. Follow the existing connection-delivery attempt fence, Monitor source outbox recovery and local consumer receipt patterns, without copying an early receipt return that skips reauthorization.

## Lifecycle revision and provenance

Add a monotonic application lifecycle revision covering application and child environment archive/restore, environment pause/resume, and child creation/deletion. Every relevant writer locks the application first and increments that revision. Telemetry counter changes do not increment it. Timestamps alone are insufficient for stale-receipt detection.

Core must not treat a legacy-identity-only resource as unmapped. Contradictory, incomplete, cross-workspace or orphaned mappings block restoration until reconciled. App-wide restore requires authority over every affected child. Only exact existing child maps in the same project may be reconciled, with importer provenance distinguishing parent-derived archive from independently archived canonical state. Preserve each native child's own deleted/paused state; do not create replacement ownership records or broadly activate unrelated mappings.

Implement a durable progress/retry surface and scheduler recovery, then author regressions for interruption between source and projection, duplicate requests, expired leases, concurrent rearchive, revoked authority, changed mappings, mixed archived/paused children, unmapped legacy behavior and unchanged tokens/checks. Keep automated execution deferred until the approved source implementation is complete.

## Implemented recovery and database details

The provider locks application, native workspace, environments, and receipt in that order. SQLite ignores `FOR UPDATE`, so a no-op application revision update reserves its writer before lifecycle reads; the final Core transaction similarly reserves its request writer before authority reads. Lock contention rolls back and uses bounded durable retries. No-op reservations do not increment lifecycle revisions or change timestamps.

Core fingerprints the current actor's native identity maps, native workspace identity, exact resource/environment/parent maps, child inventory, lifecycle/provenance, shared environment bindings, and selected product attachment. Explicit resource-only links remain valid; identity-only/orphan/conflicting mappings require reconciliation. Environment restoration binds an active native parent and its exact canonical mapping or verified absence.

Imported inactive Monitor project attachments can reactivate only with proven importer-owned parent archival. Neither workspace grants nor subscriptions change. New imports mark archive origin explicitly. Older untouched imports use conservative batch/map/source/timestamp evidence; ambiguous records require reconciliation. Restored provenance is consumed. Shared canonical environments retain their lifecycle when another resource is bound; archived shared environments require reconciliation before source mutation.

Expired leases stop automatic recovery after twelve attempts. A current manager can retry interrupted work or create a new immutable request after state/mapping changes; a replacement manager never inherits the old request's actor. The Core scheduler discovers the recovery command through the module console registration.
