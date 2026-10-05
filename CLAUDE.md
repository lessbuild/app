# BuildPusher platform v3

One platform that brings Deploy, Infrastructure, Monitoring, Security, Analytics (and Audit, switched off for now) together as services of a single account, dashboard and bill. v3 started fresh on the orphan branch `platform-v3` and now lives on `developer`, the default branch: a Laravel JSON API (`api/`) and a Nuxt app (`web/`), reusing v2's code wherever it fits. The plan and order of work are in [docs/plan.md](docs/plan.md).

## Repository layout

- `api/` — Laravel: the JSON API for the app (`routes/app.php`, under `/api/app`), the token APIs (`routes/api.php`: `/api/v1`, `/api/v2`, ingest, MCP, SCIM), the Filament admin (`/admin`), sign-in round trips and machine endpoints (`routes/web.php`), and `routes/frontend.php`, which only *names* the Nuxt pages so `route()` can link to them from emails and notifications. Run `composer`, `php artisan`, Pint, PHPStan and its tests from `api/`.
- `web/` — Nuxt 4 (Vue 3, TypeScript, Tailwind CSS 4), rendered on the server: every page people see. Run `npm` from `web/`.
- `deploy/`, `docs/`, `sdk/`, `integrations/`.

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
- `app/Data/<Area>/` — `final readonly` data objects. **They hold only scalars, enums, dates, arrays and other Data objects, never models**, because controllers return them as JSON as they are.
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
   * @return \Illuminate\Http\JsonResponse
   */
  ```

  Properties get a summary line and `@var`. Promoted constructor properties are described on their `@param` lines. Write a method's summary as an instruction ("Get the monitor's checks.", "Determine whether the person may…"); it explains what the member is for, not just its name. `pint.json` turns off `no_superfluous_phpdoc_tags` so Pint keeps these tags, and `ArchitectureTest::test_every_method_and_property_is_documented` enforces the rule.
- Existing code isn't a ceiling. When a pattern you meet is weak (unclear names, tangled methods, missing docs), improve it rather than copying it.
- Architecture tests in `tests/Architecture` enforce these rules. Keep them passing.

## API (api/)

- `/api/app/*` is the Nuxt app's API on the `web` middleware group: the session cookie signs people in and writes send `X-XSRF-TOKEN`. Fortify runs headless under `/api/app/auth`.
- Controllers answer JSON: a Data object (or a list of them) for reads, the saved Data object for writes, or `{ "redirect": "/path" }` when the app should move on. Validation errors are Laravel's 422; `RuleViolation` maps to a field error.
- Each slice rewrites the area's v2 feature tests (in `tests/Pending`) as API tests and moves them back into `tests/Feature`.

## UI (web/)

- Use the Signal components (`web/app/components/signal`, forms in `web/app/components/form`) and Signal's CSS classes for every page. Don't add inline styles or one-off components when a Signal primitive exists; add a primitive when one is missing.
- **Improve the UI where it helps** (the owner asked for this with the rewrite): clearer hierarchy, fewer clicks, better empty states and feedback, consistent spacing. Keep the Signal look.
- Create and edit screens open in a dialog on the page (`UiDialog`, `FormDialog`), linked with `?dialog=<id>` so Back closes them and a link opens them; a full page backs the ones linked from outside the app. Tasks with dependent steps use `Wizard`; destructive actions confirm in `DeleteDialog`.
- Pages load data with `useApi()` (`app/composables/useApi.ts`, server-rendered with the person's cookies) and write with `ApiForm` or `send()` (`app/utils/client.ts`); after a write the page's data reloads (`refreshPage()`) or the app follows the API's `redirect`. Signed-in pages use `definePageMeta({ layout: 'app' })`; the signed-in middleware loads the shell for them.
- Components are named by file without folder prefixes, never after an HTML element (`UiButton`, `UiDialog`, `ApiForm`). Each component's `<script setup>` starts with a comment saying what it is for.
- Every user-facing string goes through `t()` / `tc()` from `useT()` and must exist in `api/lang/{es,fr,de,pt}.json`; `npm run messages` fails on a missing one.
- Accessible by default: real labels, focus moved into dialogs and wizard steps, keyboard paths, colour never the only signal, light and dark both checked.
- The admin panel stays Filament (`api/app/Filament`): its writes go through Actions; its resources set `$shouldSkipAuthorization` because the panel's middleware decides access.

## Workflow

- Run each slice's tests, Pint and PHPStan (api) and lint, typecheck and build (web) as the slice lands. Commit small, reviewable slices and push after every commit.
- A feature isn't done until everything public that describes the product is updated in the same slice: the changelog, the home and service pages, the help centre, the comparison pages and the roadmap.
- Public contracts (tracker, ingest endpoints, Deployer API v1, webhooks, provisioning and deployment callbacks, status page URLs and badges) keep working unchanged.
