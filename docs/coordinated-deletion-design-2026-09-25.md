# Coordinated account and workspace deletion

Status: source implementation and review complete; migration and behavioral acceptance pending. Not deployed. Automated tests are authored but deliberately unrun until all approved plan source work is complete.

## Product behavior

The shared account security page and the workspace owner's administration page open a Signal deletion review on the authentication host. Review is read-only. It lists the affected workspaces, product data counts, retained records, export links, and blockers. Confirmation requires the current password when present, MFA when enabled, exact email/workspace name, and explicit acknowledgement. Passwordless accounts without MFA require a recent shared login session.

An accepted request immediately disables affected Core workspaces, memberships, grants, projects, and connections. Account deletion also revokes all shared authentication sessions. It never destroys remote infrastructure or automatically cancels a paid subscription. Paid periods must have ended and billing reconciliation must be settled first. Other workspace members and foreign memberships must be resolved before confirmation.

The browser receives an encrypted, HttpOnly, host-only recovery cookie before confirmation. Its path follows the actual authentication route prefix. A saved progress link and separate recovery key permit status access and retry after logout or a lost confirmation response. The secret is absent from URLs and validation flash data. Unknown and unauthorized receipt links show the same recovery form. Pages forbid caching and referrer forwarding. A capability can only resume the exact accepted request; it cannot expand scope or create another deletion.

## Product boundary and state machine

`ProductDeletionProvider` owns native preflight, preparation, and purge. Core stores immutable requests, target payloads, identity-map snapshots, and per-product steps. Native account/workspace IDs are distinct from their Core canonical IDs. Each workspace step owns its own native fence and receipt; an account step must not overwrite those workspace receipts.

1. Every step prepares: validate owner/scope/billing, persist its native tombstone, reject new activity, and drain claimed work.
2. Core advances only when every step is ready and live connection/restoration claims have drained.
3. Purge workspace steps. A module completes relational cleanup and a matching receipt in one native transaction. Files require a durable cleanup manifest before relational acknowledgement.
4. Purge account steps only after all workspace steps have acknowledged completion.
5. Core rechecks the accepted scope and scrubs personal data. Canonical IDs and identity/resource tombstones remain to prevent reattachment. Settled provider IDs and necessary financial references remain, with nonessential metadata scrubbed. Core reports completion only after every native acknowledgement.

No Core transaction spans a native operation. Claims have generations and expiring worker leases; retries retain immutable request/step/payload identifiers. A native completion receipt permits recovery after a source commit followed by a lost Core acknowledgement. A missing native target without that receipt is an error. Expired Core claims can be recovered; ambiguous native external-operation outcomes stay blocked until reconciled. Deletion cannot be canceled after acceptance.

## Preventing provisioning races

First-use product account/workspace projection registers a durable Core operation before any source work. Starting it and accepting deletion serialize on the Core actor. Unfinished projection blocks deletion. Successful source projection and Core mapping remove the barrier; a caught source exception leaves a retryable barrier. A subsequent product visit can reconcile that operation. A crashed writer is never presumed stopped merely because time passed.

For an actual crashed projection, operators can inspect `identity-projections:recover <operation-ulid>`. Only after stopping the HTTP and queue processes that could still own it may they use `--apply --workers-stopped`. This marks the claim retryable; it does not remove the deletion barrier or manipulate native data. Restart the processes and revisit the app to finish its normal idempotent projection.

## Native activity and billing reconciliation

Deployer registers durable native activity claims before authenticated mutations and the remote jobs that do not already have durable operation records. Request claims resolve bound resource ownership and retain actor scope for personal recipes; ordinary validation and authorization failures release their claims. Unknown worker outcomes and interrupted remote work remain blockers. Preparation and claim creation serialize on the same native actor/workspace rows. Jobs starting after a fence reject that target before contacting a remote system.

`php artisan buildpusher:deletion-claims` inspects unresolved Deployer activity. An operator can resolve one exact group with `php artisan buildpusher:deletion-claims <claim-group-uuid> --resolve-stopped --reason='confirmed stopped after investigation'` only after establishing that the worker and its related remote effects have stopped. There is no automatic age-based release. This does not retry or reverse the remote operation. None of these recovery actions was executed during implementation.

Monitor retains a verified Stripe event as pending while a workspace is fenced. Core still reconciles that financial event against the deleting workspace. Only a durable Core terminal acknowledgement clears the native billing barrier. The reconciler repairs both gaps: a native event committed before its Core record, and a Core outcome committed before its native acknowledgement. A completed Core tombstone is never reactivated by a late event. New or continuing obligations block final cleanup, including a subscription's remaining paid or trial period.

## Retention and recovery limits

- Remote servers, third-party services, and externally stored deployment backups are not destroyed by deleting Buildpusher control-plane data. Their owners remain responsible for those services.
- Deletion receipts, source/canonical identifiers, and settled billing identifiers remain. Existing operator backups follow the established backup retention schedule; this workflow does not rewrite historic database backups.
- Analytics removes exports attributable to surviving export records, including timestamped retry files, using a durable file manifest. Historical files with no remaining ownership record cannot safely be swept across tenants; they require an operator inventory and reconciliation.
- Monitor does not silently discard uncertain external sends. Existing stale telemetry, notification, probe, and outbox claims may require their existing recovery process or operator reconciliation before deletion can finish.
- Account cleanup must preserve resources belonging to other tenants even when a legacy creator/user foreign key points at the deleting account. Reassignment or reconciliation is a blocker where necessary.

## Deferred acceptance

Run isolated migration rehearsals, provider cleanup fixtures, failure injection at every source/Core acknowledgement boundary, concurrent projection/deletion checks, native worker fencing, receipt browser recovery, password/MFA confirmation, unchanged unrelated-tenant data, billing drift, file storage failures, and signed-in mobile/desktop Signal UI checks after all plan source work is complete. Do not use real customer deletion to validate the implementation.

Production remains on the independently deployed pricing hotfix while this source work is reviewed. The additive Core and native migrations must be deployed together with these new entry-point fences; no deletion migration or workflow has been executed against production during implementation.
