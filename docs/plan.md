# BuildPusher platform-v3 — plan

Started 2026-10-03. platform-v2 keeps serving production until v3 replaces it.

## Decisions (owner)

| Topic | Decision |
|---|---|
| Start | **Fresh**, on the orphan branch `platform-v3`, reusing v2's code wherever it fits |
| Frontend | **Nuxt 4** (Vue 3, TypeScript, server-rendered) with **Tailwind CSS 4**, keeping the Signal design. Started in Next.js; switched to Nuxt on the owner's request on 2026-10-03, before any page went live |
| Backend | **Laravel**, following v2's code rules (below), as a JSON API |
| Data | **Empty database**: no carry-over, so the schema may be tidied |
| Admin | **Filament** stays in Laravel at `/admin` |
| Audit | Built, but **off** (`AUDIT_ENABLED=false`) until the production server is upgraded |
| UX | Create and edit screens open as **modals** (with real URLs); multi-step tasks are **wizards**; **improve the UI where necessary** while keeping the Signal look |

## Layout

```
api/    Laravel: the JSON API (/api/app for the frontend, /api/v1 and /api/v2 for tokens), Filament (/admin),
        the public endpoints (ingest, tracker, webhooks, callbacks, CLI, status JSON and badges) and mail
web/    Nuxt: every page people see, signed in or not
deploy/ docs/ sdk/ integrations/
```

## Backend rules (from v2's CLAUDE.md, unchanged)

- Organised by type, grouped by feature area. One single-action controller per route (`__invoke` only), thin:
  validate (Form Request), authorise (Policy), call an Action or Query, respond.
- All writes through Actions (`handle()` only). Reads through Queries returning `final readonly` Data objects.
- External systems behind contracts in `app/Contracts`, faked in tests. Plan limits through `Entitlements`.
- Every method and property documented (summary, `@param`, `@return`; `@var`), enforced by the architecture tests.
- Pint, PHPStan and tests for every slice; commit and push after each.

New for v3:

- Controllers return JSON: Data objects are serialised as they are, so **Data objects hold only scalars, enums, dates,
  arrays and other Data objects — never models** (`App\Data\Concerns\SerializesToJson`). Models never reach a response
  directly.
- The frontend API is `/api/app/*` on the `web` middleware group: the session cookie signs people in, writes carry
  `X-XSRF-TOKEN`. Sign-in, sign-up, two-factor, passkeys and password reset are Fortify in headless mode under
  `/api/app/auth`.
- Validation errors are Laravel's usual 422 JSON; rule violations (`RuleViolation`) map to field errors as in v2.

## Frontend rules

- Pages load data from `/api/app` with `useApi()`, rendered on the server as the signed-in person (the cookies are
  forwarded, and cookies Laravel refreshes are passed back); writes go through `ApiForm` or `send()` (CSRF header,
  422 → field errors, 423 → "Confirm it's you" and retry).
- Signal lives in `web/`: the CSS tokens, presets and component classes (from v2's `resources/css`), the icons, and Vue
  components in `app/components/signal`. Light and dark, palettes, density and contrast all keep working.
- Every user-facing string goes through `t()` and is translated into es, fr, de and pt (`web/messages`).
- Create and edit screens are dialogs on the page linked with `?dialog=<id>` (a full page backs those linked from
  outside the app); multi-step tasks use `Wizard`. Destructive actions confirm in a dialog.
- Accessible by default: labels, focus management in dialogs and wizards, keyboard paths, colour never alone.

## Order

0. **Scaffold**: the Laravel app with v2's domain code (models, migrations, actions, queries, data, services, jobs,
   policies, enums, platform, support, contracts, notifications, Filament) and no Blade pages; the Nuxt app with
   Signal, i18n, the shell, `Wizard`, forms and dialogs. CI for both.
1. **Identity**: sign-up, sign-in, two-factor, passkeys, social and SSO sign-in, email verification, password reset,
   invitations; the shell (`/api/app/shell`).
2. **Projects and account**: dashboard, projects (wizard), setup guide, domains, environments, services; account
   members, roles, API tokens, audit log, webhooks, clients, providers, security, settings; personal settings.
3. **Billing**: plans, usage, invoices, Stripe checkout and portal.
4. **Deploy**, 5. **Infrastructure**, 6. **Monitoring** (incl. telemetry and alerts), 7. **Security**,
   8. **Analytics**, 9. **Status pages**, 10. **Public site** (home, services, pricing, compare, changelog, roadmap,
   help, API docs, legal; SEO), 11. **Recipes, notifications, assistant, onboarding**, 12. **Audit** (off).
13. **Cut-over**: deploy v3 next to v2, switch Caddy, retire v2.
    Before it: security headers for the Nuxt pages (CSP with a nonce for the theme script, HSTS, frame and referrer
    policies; Laravel's SecurityHeaders only covers its own responses), and Caddy routes for `POST` to the email
    links that mail clients unsubscribe with in one click (`laravelPosts` in `web/server/middleware/laravel.ts`).

Each slice moves the area's backend (JSON controllers, requests, tests rewritten as API tests) and its pages together.

## Progress

- **0–1. Scaffold and identity**: done.
- **2. Projects and account**: done. Projects (overview, setup guide, domains, settings, services, templates);
  account (members, invitations, API tokens, audit log with streams, webhooks, security with SSO/SAML/SCIM,
  providers, clients, inventories, settings); personal settings; notifications; search with the command palette;
  saved views; feedback; the footer. Left for later slices: the GitHub App connect flow (Deploy), the setup guide's
  in-page forms for adding a website or an Analytics site (Infrastructure, Analytics), and white-label branding on
  status pages (Status pages).
- **3. Billing**: done.
- **4. Deploy**: done. Repositories (connect dialog or page, deploy now / a version / later, push webhook, build
  cache, previews settings), deploys (followed live by polling the status endpoint, release notes, approvals,
  destructive migrations, release analysis, promotion, cancel / redeploy / roll back), comparing two deploys,
  approving from chat links, previews, pipelines, environments (controls and freezes, settings and build server,
  variables and secret syncs, workers, resources, automation, recipes, notifications), configuration as code with
  reviews and receipts, the public release notes page and the GitHub App's repositories.
- **5. Infrastructure**: done, with the recipe library and gallery (slice 11 keeps notifications, assistant and
  onboarding). Servers (create from a provider's catalog, import over SSH, provisioning followed live, resources,
  alerts, diagnostics and disk clean-up, database recovery and read replicas, cron jobs, processes, firewall,
  services, logs and log shipping, snapshots, Node.js, the command history and the in-browser terminal), websites
  (create or adopt, health, domains with Cloudflare's CDN and firewall, database inspection, users and copies,
  backups and schedules, files, PHP, Reverb, Caddy directives), backups, load balancers, storage buckets, costs and
  bills, and moves from Forge or Ploi. One-time passwords come back in the JSON (`secrets`) and show on the next page.
  Infrastructure's routes sit in their own project group without nested bindings: its records belong to the account.
- **6. Monitoring**: done. Monitors (HTTP, DNS, TLS, TCP, heartbeat, queue and multi-step flows, with keys shown
  once), services you depend on, incidents (acknowledge, assign, notes, post-mortems drafted from the timeline and
  published to a status page), alert rules with routing and escalation, destinations, on-call rotations and cover,
  maintenance windows, alert noise, service level objectives with burn rate and CSV export, dashboards, status pages
  (components, updates, badges, custom domains), and telemetry: issues with tickets, events, traces, the service
  map, releases and deployments compared side by side, setup with ingest keys and browser errors, deliveries and
  metrics. The public status pages themselves come in slice 9.
- **7. Security**: done. The overview (score and grade, each check with Scan now, the deploy gate per environment in a
  dialog, the worst open findings), findings with filters and one-click server fixes, ignore and reopen in dialogs,
  servers (update windows, installing updates now, SSH access given and removed), the Cloudflare firewall, attacks
  and blocking settings, access reviews as a wizard (members, API tokens, SSH access, then confirm), and compliance
  with the evidence pack download. The deploy gate callback is unchanged.
- **8. Analytics**: done. The report (period, comparison and filters in the address, every list narrowing the report,
  notes and releases under the chart, page speed, search terms, engagement, goals, a live panel refreshed every 15
  seconds, saved views and CSV exports), sites (added in a dialog; their settings, snippet, verification, Search
  Console, raw export, Google Analytics imports, view-only access, reports and alerts and sharing, each change in a
  dialog), goals, funnels (added with a wizard), campaigns (link builder, results, ad spend from a CSV or a connected
  ad account) and Explore's tabs (insights, paths, properties, items, attribution, clicks, forms, A/B tests,
  retention). Shared reports, their embed (the one page other sites may frame) and view-only links are public Nuxt
  pages on public endpoints. OAuth returns report back with `?notice=` or `?error=`.
- **9. Status pages**: done. Public pages in Nuxt on public endpoints: the status page (white-label branding,
  systems with 30-day history, current and planned updates, the last 30 days, subscribing by email, Slack or a signed
  webhook), monthly uptime, BuildPusher's own status, confirming a subscription (posted once the page loads, so mail
  scanners don't confirm it) and unsubscribing. A custom domain's root renders its page (the home page asks
  `/api/app/status-domains/{host}`), and server/middleware/status-domains.ts 404s the rest of the app there. The embed
  widget, badges, JSON reports, legacy addresses and one-click unsubscribe stay in Laravel, unchanged. Set
  NUXT_PUBLIC_APP_HOST in production so the app's own host skips the domain lookup.
- **10. Public site**: next.
