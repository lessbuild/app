# BuildPusher Platform v2

One Laravel application that brings Deploy, Infrastructure, Monitoring and Analytics together as services of a single platform, in the way Cloudflare groups its products under one dashboard. The plan lives in [docs/platform-v2-plan.md](docs/platform-v2-plan.md).

## Product model

- **Account** → **Projects** → **Environments**. A project has domains and enabled services.
- Services register themselves through the `PlatformService` registry. The shell, billing and onboarding read that registry, so they never hard-code service lists.
- Billing: one Stripe subscription per account, with one item per chosen service tier, add-on or meter. Limits are checked through `Entitlements`, never ad hoc.

## Code rules

- Bounded contexts live in `app/Domain/<Context>/{Models,Actions,Data,Enums,Events,Listeners,Jobs,Policies,Queries,Contracts}`.
- **Actions** have one public `handle()` for one use case. **Queries** return read models. **Data** classes are `final readonly`.
- Controllers, Form Requests and Livewire components in `app/Http` stay thin: validate, authorise, call an Action or Query, and respond.
- Contexts talk to each other through Actions, Queries and domain events, never by reaching into another context's models from HTTP code.
- External systems (Stripe, GitHub, cloud providers, SSH, DNS) sit behind interfaces in `app/Services`. Tests bind fakes.
- Every mutation is authorised by a Policy. Secrets use encrypted casts. Sensitive columns are never mass-assignable.
- Architecture tests in `tests/Architecture` enforce these rules. Keep them passing.

## UI

- Use the Signal component library (`resources/views/components/signal`, `x-signal.*`) for every page. Don't add inline styles or one-off components when a Signal primitive exists.
- `/_gallery` shows every component and is the visual reference (local environment only).

## Workflow

- Run each slice's tests, Pint and PHPStan as the slice lands. (This replaces the old branch's "don't run tests yet" rule; the user approved the change for `platform-v2` only.)
- Commit small, reviewable slices and push after every commit. The development container can be reset and lose unpushed work.
- Public contracts from the old apps (tracker, ingest endpoints, Deployer API v1, webhooks, status page URLs) must keep working unchanged; see the plan's compatibility phase.
