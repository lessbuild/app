# Plan: the Audit service, a Next.js frontend, and Laravel as the API

Asked for on 2026-10-02/03. Decisions made with the owner:

| Topic | Decision |
|---|---|
| Order | Restructure first, then build **Audit as the first service on the Next.js frontend**, then move the rest service by service |
| Admin panel | **Stays in Laravel** (Filament at `/admin`) |
| Competitors | The customer lists them **and** Audit suggests them (search API when `BRAVE_SEARCH_API_KEY` is set, Claude otherwise) |
| Domains | **One domain.** Next.js serves the pages; Laravel answers `/api/*` and the existing public endpoints, unchanged |

## Repository layout

```
api/        the Laravel application, moved here unchanged (git mv), now the API
web/        the Next.js app (App Router, TypeScript, Tailwind 4)
deploy/     deploy scripts for both
docs/       plans
```

- `api/` keeps every Action, Query, Model, job, command, test, the Filament admin, mail views and the public endpoints (ingest, tracker, webhooks, Deployer API v1, status page JSON/badges, CLI). Blade views are deleted once the Next.js page that replaces them ships; mail and admin views stay.
- The browser API for the new frontend lives at `/api/app/*` (`api/routes/app.php`), single-action controllers as now, returning JSON built from the existing `Data` objects. Sign-in is Sanctum's first-party cookie session (`/sanctum/csrf-cookie`, Fortify's JSON responses), so no tokens sit in the browser.
- Policies, Form Requests and Entitlements are unchanged: the JSON controllers call the same Actions and Queries the Blade controllers call today.

## Serving both during the move

Caddy sends `/api/*`, `/sanctum/*`, `/admin*`, Filament/Livewire assets, ingest/tracker/webhook paths, `/cli/*` and `/status/*` straight to Laravel and everything else to Next.js. Next.js has a `fallback` rewrite to Laravel, so any page not moved yet is still served by Blade. Each slice can ship on its own; nothing waits for a "big bang".

Next.js is built in CI (`output: 'standalone'`) and the artifact is copied to the server; the production server is too small to build it (see Risks).

## Frontend shape

- **Design:** the Signal theme moves over as-is: `resources/css/signal/*` tokens and component classes become `web/app/signal.css`; each `x-signal.*` Blade component becomes a React component in `web/components/signal/`. Light/dark, palettes, density and contrast settings keep working. Improvements only where they help (loading states, optimistic updates, keyboard focus in dialogs).
- **Modals:** quick actions and detail pages open as modals using Next.js intercepting routes (`@modal/(.)…`). Every modal has a real URL, so a refresh or shared link opens the full page. This replaces today's `?dialog=` mechanism.
- **Wizards** where a task has steps that depend on each other:
  - new project (services → environments → domain)
  - add a cloud provider and create a server (provider → region/size → runtime → review)
  - add a website (server → domain → runtime → repository → first deploy)
  - connect a repository and environment
  - new monitor (type → target → schedule → alerts)
  - Analytics site (domain → tracker snippet → verify)
  - new audit (site → journeys → competitors → schedule)
  - sign-up and onboarding (account → first project → services)
- **Public site** is rendered on the server (SEO kept: titles, canonical, JSON-LD, sitemap, share images). Content stays in Laravel config and comes from `/api/app/site/*`.

## The Audit service

What it does: a headless browser visits a site as a person would, tries real tasks (find pricing, sign up, contact, buy), rates how each flow went, does the same on competitors' sites, and shows what to improve with annotated screenshots and a rendered mock-up of the fix.

- **Naming:** the service is "Audit" (key `audit`), but its code area is `SiteAudits` because `Audit` already means the account audit log.
- **Data:** `site_audits` (project, site URL, journeys, competitors, schedule), `site_audit_runs` (status, overall and per-category scores, cost), `site_audit_journeys` (per site: goal, steps, success, time, friction), `site_audit_steps` (URL, action, screenshot, element boxes), `site_audit_findings` (category, severity, evidence screenshot with highlighted boxes, what to change, optional mock-up), `site_audit_competitors`.
- **Runner:** a queued job (`site-audits` queue) starts a Node/Playwright script (`api/resources/site-audit/runner.mjs`). Claude drives each journey: it gets a screenshot and a numbered list of the page's interactive elements and answers with one action (click, type, scroll, back, done or gave up) through a tool call. Deterministic checks run alongside: Core Web Vitals and page weight, axe accessibility, SEO basics, mobile layout, broken links, forms.
- **Rating:** each journey is scored on success, steps, time, dead ends and friction. Categories: navigation, conversion flow, performance, accessibility, SEO, content clarity, mobile, trust. The same journeys run on each competitor for a side-by-side table and chart.
- **Visuals:** findings carry the screenshot with the problem elements outlined. For layout and copy findings, Claude rewrites that section's HTML and Playwright renders it, giving a before/after.
- **Competitor suggestions:** the Brave Search API when `BRAVE_SEARCH_API_KEY` is set, otherwise Claude suggests from the site's content; the customer confirms.
- **Safety:** only public `http(s)` addresses; every browser request is checked against private and reserved ranges after DNS resolution; `robots.txt` is honoured; at most 30 pages and 2 requests a second per site; a clear user agent (`BuildPusherAudit/1.0 (+https://buildpusher.com/help/audit-bot)`).
- **Billing (AuditCatalog):** Free: 1 audit a month, 1 competitor, 10 pages. Pro $29: 10 audits, 3 competitors, monthly schedule. Business $99: 40 audits, 5 competitors, weekly schedule, white-label PDF. Meter: extra audit $4.
- Public pages updated in the same slice: changelog, home/service page (`config/marketing.php`), help guide, compare pages, roadmap.

## Slices

0. **Restructure:** `git mv` the Laravel app into `api/`; fix paths in CI, deploy, Pint/PHPStan/Playwright configs, README/CLAUDE.md. All tests still pass.
1. **Web foundation:** Next.js app, Signal port, API client with CSRF, auth pages (sign in, sign up, 2FA, passkeys, reset), app shell (sidebar from the service registry API, top bar, command palette, notifications), modal and wizard primitives, Caddy and deploy changes, CI job.
2. **Audit backend:** models, migrations, Actions, Queries, runner, Claude driver, scoring, competitor suggestions, billing catalogue, JSON API, tests.
3. **Audit frontend:** list, the new-audit wizard, live run progress, report (scores, competitor comparison chart, findings with visuals), schedules; public service page, help guide, changelog.
4. **Public site** in Next.js.
5. **Account, projects, settings, billing, setup guide** (with the new wizards).
6. **Deploy**, 7. **Infrastructure**, 8. **Monitoring**, 9. **Security**, 10. **Analytics**.
11. **Status pages** (including custom domains and embeds).
12. **Cut-over:** remove the fallback rewrite and the Blade views that were replaced; Laravel serves only `/api`, admin, mail and the public endpoints.

Each slice: tests, Pint, PHPStan (api) and lint, type-check, Playwright (web); commit and push.

## Risks

- **Server size.** Production has 1 vCPU and ~1 GB of RAM, already swapping, with the root disk 90% full. Next.js (~200 MB) plus Chromium for audits (~300 MB a page) won't fit. Before slice 1 goes live it needs at least 2 vCPU / 4 GB, or audits run on a separate worker machine.
- **Claude cost per audit.** About 40 vision calls an audit with three journeys on the site and two competitors. The model is `AUDIT_MODEL` (default `claude-sonnet-5`); prices above leave margin at that rate.
- **Size of the move.** 278 views and ~600 routes. The fallback rewrite keeps the app whole while it's half moved.
