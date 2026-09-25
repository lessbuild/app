# Monitor restoration implementation progress

Status: source implemented on `feature/unified-platform`; migrations and automated regression execution are deferred, and this feature is not deployed.

Monitor application and environment restoration now uses an explicit lifecycle workflow. Retained archive views remain readable with current workspace/project/product access, while restoring requires current management authority and an already active shared project. Historical export permissions never authorize restoration.

Core stores immutable requests before native mutation. Monitor restores only the requested row and stores a receipt in the same native transaction. Application lifecycle revisions, exact mapping and actor identity fingerprints, leased attempt generations, fresh authorization, and source locks fence the final Core projection. SQLite writer reservations account for its lack of row locks. Interrupted work uses bounded retries and scheduler recovery; expired attempts cannot complete a newer request. Progress pages support a new authorized manager and fresh requests after reconciled mappings without rewriting the original actor or intent.

Application restoration preserves independently archived children and native pauses. It does not restore revoked tokens, disabled checks, subscriptions, or automation. A proven imported Monitor project attachment can reactivate; independent or ambiguous attachment changes require reconciliation. Multiple resources sharing a canonical environment retain that environment's lifecycle. An archived shared environment requires reconciliation before native mutation. Explicit resource-only links and genuinely unmapped legacy operations remain supported; orphan legacy identities do not bypass mapping checks.

New imports record archive origin. Older imported records require exact importer identity and unchanged canonical metadata evidence to infer parent archival; ambiguous records remain blocked. This compatibility inference does not claim to reconstruct arbitrary manual database edits.

Additive migrations, not yet applied:

- Core: `2026_09_25_220000_create_resource_restoration_requests.php`
- Monitor: `2026_09_25_140000_add_resource_restoration_receipts.php`

Regression source covers source-commit interruption, idempotency, rearchive and mapping changes, authority revocation, expired leases, SQLite concurrent source writes, child archive/pause preservation, shared environments, successor requests, and unchanged credentials/checks. Execution remains deferred under the user's plan-wide instruction. Static validation is recorded after the integration pass below.

Integration validation passed: sequential syntax checks for all 41 changed PHP files, explicit-file Pint, module dependency boundaries, whitespace checks, and the Vite production asset build. All 509 compiled Blade files passed syntax checks, and the development view cache was cleared afterward. Independent static review of the Core and Monitor implementations found no remaining substantive source blocker. These checks do not replace the deferred behavioral/concurrency suite, migration rehearsal, authenticated browser acceptance, or production validation.

The separate public pricing hotfix is live at production revision `49371e1`. This restoration feature and the earlier native-access/rollout/digest changes remain source-only.

The full unified plan remains in progress. Coordinated account/workspace deletion, remaining Core administration, project blueprints, native notification projection, complete resource-map inventory, and scoped credential mutations remain source work in the [remaining checklist](../implementation-remaining-2026-09-25.md).
