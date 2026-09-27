# BuildPusher Platform v2

One Laravel application that brings Deploy, Infrastructure, Monitoring and Analytics together as services of a single platform, in the way Cloudflare groups its products under one dashboard. The plan lives in [docs/platform-v2-plan.md](docs/platform-v2-plan.md).

## Product model

- **Account** → **Projects** → **Environments**. A project has domains and enabled services.
- Services register themselves through the `PlatformService` registry. The shell, billing and onboarding read that registry, so they never hard-code service lists.
- Billing: one Stripe subscription per account, with one item per chosen service tier, add-on or meter. Limits are checked through `Entitlements`, never ad hoc.

## Code rules

A conventional Laravel layout, organised by type (not by domain). Within a type, classes are grouped by feature area (`Accounts`, `ApiTokens`, `Audit`, `Billing`, `Notifications`, `Projects`, `Users`; services add their own in Phase 4).

- `app/Http/Controllers/<Area>/` — **one single-action controller per route** (`__invoke` only), named for what it does: `Projects/StoreProjectController`, `Account/InviteMemberController`. Controllers stay thin: validate (a Form Request in `app/Http/Requests` when it's more than a line), authorise, call an Action or Query, respond.
- `app/Actions/<Area>/` — one use case each, with a single public `handle()`. All writes go through Actions. (`app/Actions/Fortify` holds Fortify's adapters.)
- `app/Queries/<Area>/` — read models for pages and APIs; they return `app/Data` objects.
- `app/Data/<Area>/` — `final readonly` data objects.
- `app/Models`, `app/Enums`, `app/Events/<Area>`, `app/Listeners`, `app/Notifications`, `app/Policies`, `app/Exceptions`.
- `app/Services/` — longer-lived services and every external system (Stripe, GitHub, cloud providers, SSH, DNS), external ones behind a contract in `app/Contracts` so tests bind fakes.
- `app/Platform/` — the `PlatformService` registry and each service's billing catalogue.
- `app/Support/` — small stateless helpers.
- Actions, Queries, Models and Data never depend on the HTTP layer.
- Every mutation is authorised by a Policy. Secrets use encrypted casts. Sensitive columns are never mass-assignable.
- Architecture tests in `tests/Architecture` enforce these rules. Keep them passing.

## UI

- Use the Signal component library (`resources/views/components/signal`, `x-signal.*`) for every page. Don't add inline styles or one-off components when a Signal primitive exists.
- `/_gallery` shows every component and is the visual reference (local environment only).

## Workflow

- Run each slice's tests, Pint and PHPStan as the slice lands. (This replaces the old branch's "don't run tests yet" rule; the user approved the change for `platform-v2` only.)
- Commit small, reviewable slices and push after every commit. The development container can be reset and lose unpushed work.
- Public contracts from the old apps (tracker, ingest endpoints, Deployer API v1, webhooks, status page URLs) must keep working unchanged; see the plan's compatibility phase.
