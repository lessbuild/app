# UI modernization progress

## Purpose

This ledger records the BuildPusher interface modernization. Each slice must
preserve routes, response status codes, validation keys, named error bags,
flash messages, authorization behavior, Livewire contracts and persisted
values. A slice is complete only after its focused checks pass, the result is
documented, and its cohesive commit is pushed to `origin/main`.

## Baseline — 2026-09-17

- Checkout: isolated implementation checkout on `main`.
- Baseline commit: `2a5b129` (`docs: record final verification results`).
- Working tree: clean before this ledger was added.
- Required PHP: `/root/.local/share/buildpusher/php-8.5.10/bin/php` (PHP 8.5.10).
- Frontend runtime: Node 22.12.0, npm 10.9.0, locked `node_modules` present.
- Existing frontend foundation: shared Blade layouts, Tailwind 4 semantic
  theme utilities, Alpine navigation/command palette, Livewire status views,
  and existing asset/accessibility/visual browser suites.
- Fresh PHP baseline: `artisan test --fail-on-warning --fail-on-risky
  --fail-on-deprecation --fail-on-phpunit-deprecation
  --do-not-record-test-run-history` — **1,556 passed, 13,004 assertions**;
  no warnings, risky tests, deprecations or PHPUnit deprecations.
- Pint baseline: `vendor/bin/pint --test` — passed.
- Asset baseline: `npm run build` — passed with Vite 8.2.2.
- Browser asset baseline: `npm run test:assets` — **9 passed**.
- Browser accessibility and visual baseline: `npx playwright test
  tests/Browser/accessibility.spec.js tests/Browser/visual-audit.spec.js` —
  **6 passed** in 8.7 minutes.
- Disposable browser runtime: `127.0.0.1:8014`, seeded SQLite database at
  `/tmp/buildpusher-ui-baseline-20260917/database.sqlite`, array cache/queue,
  isolated local application key and no production credentials. The temporary
  server was stopped after the run.
- No live or paid-cloud acceptance was performed. The known tablet Search/
  focus expectation and the broader mobile Settings-link visual-audit issue
  were not reproduced as failures in this fresh baseline; they remain tracked
  as historical observations until the relevant journeys are explicitly
  rechecked after UI changes.

## Page inventory

### Public, documentation and authentication

Landing, pricing, access request, privacy, terms, product guide, API docs,
platform status, public status pages, login, registration, password reset,
email verification, password confirmation and two-factor challenge.

### Workspace and application delivery

Dashboard, search, projects, project creation/detail, environments,
configuration authoring/review/receipt, builds/deployments, deployment detail
and comparison, repositories, GitHub App selection, impact preview, websites,
website creation/detail, health checks, runtime logs and provisioning logs.

### Infrastructure and recovery

Servers, server creation/edit/detail, server import/assessment/review,
commands, command output, troubleshooting, providers, provider creation/edit/
detail/connection checks, databases, domains and TLS, load balancers, backups,
backup destinations, schedules, restore and verification.

### Operations and automation

Observability, environment context, investigations, metric rules, alert
destinations, incidents, status pages, system health, notifications, saved
filters, activity, command history, automation, workflow configuration,
deployment/scaling schedules, scheduled tasks and API/token operations.

### Templates, account and administration

Recipes, recipe detail/create/edit, gallery, gallery detail/compare, ratings,
favorites, reports, feedback, workspace membership, billing, costs, profile,
password, sessions, sign-in history, two-factor settings, admin analytics and
access requests.

## Initial findings

1. Desktop and mobile navigation are maintained as separate definitions. The
   mobile menu exposes almost every destination as an equal tile, while the
   desktop sidebar has a long flat list.
2. Applications, Sites, Domains, Builds and Repositories are related delivery
   concepts but are presented as unrelated primary destinations.
3. Workspace, Billing, Costs, Account and Settings are split across several
   links; Account and Settings currently lead to the same account surface.
4. Observability, Notifications, Activity, Commands and System health have no
   clear operations grouping.
5. Recipes and Gallery form one template/community area but are separate in
   the primary navigation.
6. The heading and breadcrumb partials do not provide one consistent page
   hierarchy. The breadcrumb is currently a single back link.
7. Button variants, radii, spacing, status colors and typography vary widely.
   The existing `.ternary` variant is the blue action treatment, while
   `.primary` is often a neutral surface treatment.
8. Several large views combine multiple workflows: dashboard, repository
   detail, user account, server detail, deployment status, observability,
   backups and notifications.
9. Dense inline forms and mixed list/form pages need progressive disclosure on
   mobile, especially backups, domains, load balancers and observability.
10. Existing browser coverage already protects assets, focus, mobile menu,
    visual layout and broad route rendering; new UI work must extend those
    contracts rather than weaken them.

## Proposed navigation model

The first implementation will consolidate the navigation visually without
removing or renaming existing routes.

| Group | Destinations |
| --- | --- |
| Overview | Dashboard |
| Build and release | Applications, Deployments, Repositories |
| Infrastructure | Sites, Servers, Providers |
| Data and recovery | Databases, Backups |
| Traffic | Domains and TLS, High availability |
| Health and operations | Observability, Commands, Activity, Notifications |
| Automation | Automation and API |
| Templates | Recipes and Gallery |
| Workspace | Workspace, Billing and usage, Account and security |
| Help | Product guide, Feedback |
| Administration | System health, Analytics, Access requests |

## Slice ledger

| Slice | Status | Tests | Commit / push | Next task |
| --- | --- | --- | --- | --- |
| Phase 0: inventory and fresh baseline | Complete | 1,556 PHP tests / 13,004 assertions; Pint; asset build; 9 asset-layout tests; 6 accessibility/visual tests | Pending commit and push | Add semantic design tokens and shared Blade UI primitives |

## Remaining external scope

UI verification is local/dev evidence. Production release, live acceptance,
provider acceptance, billing and external monitoring remain separate gates.
