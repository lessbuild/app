# BuildPusher Platform v2

One Laravel application that brings Deploy, Infrastructure, Monitoring and Analytics together as services of a single platform, in the way Cloudflare groups its products under one dashboard. The plan lives in [docs/platform-v2-plan.md](docs/platform-v2-plan.md).

## Repository layout

- `api/` is the Laravel application; every path under "Code rules" and "UI" below is relative to it. Run `composer`, `php artisan`, Pint, PHPStan and its tests from `api/`.
- `web/` is the Next.js frontend that replaces the Blade pages service by service; `docs/nextjs-and-audit-plan.md` has the decisions and the order. Until a page moves, Next.js falls back to Laravel for it.
- `deploy/`, `docs/`, `sdk/` and `integrations/` stay at the root.

## Product model

- **Account** → **Projects** → **Environments**. A project has domains and enabled services.
- Services register themselves through the `PlatformService` registry. The shell, billing and onboarding read that registry, so they never hard-code service lists.
- BuildPusher never hosts customers' websites or workloads (apps, databases, caches, websockets). They run on servers in the customer's own cloud accounts; we provision, deploy, monitor and analyse. Don't propose managed hosting or managed data services.
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
- **Document every method and every property** — public or private, including `__invoke`, constructors, enum methods and small private helpers. Every method docblock has a summary line, then an `@param` line for every parameter and an `@return` line (constructors excepted), even when they repeat the native types:

  ```php
  /**
   * Search jobs and filter the results.
   *
   * @param  \Illuminate\Http\Request  $request
   * @return \Illuminate\Contracts\View\View
   */
  ```

  Properties get a summary line and `@var`. Promoted constructor properties are described on their `@param` lines. Write a method's summary as an instruction ("Get the monitor's checks.", "Determine whether the person may…"); it explains what the member is for, not just its name. `pint.json` turns off `no_superfluous_phpdoc_tags` so Pint keeps these tags, and `ArchitectureTest::test_every_method_and_property_is_documented` enforces the rule.
- Existing code isn't a ceiling. When a pattern you meet is weak (unclear names, tangled methods, missing docs), improve it rather than copying it.
- Architecture tests in `tests/Architecture` enforce these rules. Keep them passing.

## UI

- Use the Signal component library (`resources/views/components/signal`, `x-signal.*`) for every page. Don't add inline styles or one-off components when a Signal primitive exists.
- The exception is the platform admin panel at `/admin`: it's Filament 5 (`app/Filament`, `App\Providers\Filament\AdminPanelProvider`, views in `resources/views/filament`, theme in `resources/css/filament/admin`). Its writes still go through Actions; its resources set `$shouldSkipAuthorization` because the panel's middleware (platform admin with a second factor, recent password) decides access. Only `/admin` pages get the looser script policy Filament needs.
- `/_gallery` shows every component and is the visual reference (local environment only).

## Workflow

- Run each slice's tests, Pint and PHPStan as the slice lands. (This replaces the old branch's "don't run tests yet" rule; the user approved the change for `platform-v2` only.)
- Commit small, reviewable slices and push after every commit. The development container can be reset and lose unpushed work.
- A feature isn't done until everything public that describes the product is updated in the same slice: the changelog (`config/changelog.php`), the home page and service pages (`config/marketing.php`), the help centre, the comparison pages (`config/compare.php`) and the roadmap (mark shipped requests).
- Public contracts from the old apps (tracker, ingest endpoints, Deployer API v1, webhooks, status page URLs) must keep working unchanged; see the plan's compatibility phase.
