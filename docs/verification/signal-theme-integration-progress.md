# Signal theme integration progress

## Slice 1 — shared theme and application shell

Status: implemented and pushed on `main` through commit `859e898`; the
follow-up compatibility/provider-control slice is pushed as `5df3f16`, and the
dashboard slice is complete and ready to commit.

The application now includes the actual Signal Starter source from:

```text
/root/Documents/Codex/2026-09-21/plan-can-you-create-a-tailwindcss
```

The vendored snapshot contains Signal's theme tokens, component styles,
generated palettes and theme data under `resources/css/signal/`. BuildPusher
defaults to Signal's `graphite` palette, `subtle` corners, comfortable density,
system appearance and the existing persisted user dark-theme preference when it
is available.

The shared Laravel shell now loads Signal's theme entry points through Vite,
uses the Signal control and surface primitives, and exposes a theme toggle in
the authenticated header. Existing BuildPusher utility names remain supported
through an explicit compatibility bridge so route behavior and page migrations
can proceed incrementally without a second palette.

Preserved contracts:

- Routes, controllers, Livewire components, forms and validation behavior.
- Modal, navigation and scroll-lock JavaScript hooks.
- Existing user `preferences.theme` values (`light` and `dark`).
- Existing non-JavaScript and responsive navigation markup.
- Local-only assets and the current locked dependency set.

Evidence:

- `npm run build` — passed.
- `git diff --check` — passed.
- `php artisan view:cache` — passed.
- `php artisan test tests/Feature/LocalUiAssetTest.php --do-not-record-test-run-history` — 23 passed, 475 assertions.

Follow-up work in progress:

- Legacy semantic utility names now resolve to Signal page, surface and ink
  roles instead of colliding with Signal's accent utilities.
- Native modal and filter headers use Signal panel/control primitives.
- Provider choices use Signal's text-based `ui-choice` and `ui-check`
  components; provider tokens remain text-free from the rendered page.

Evidence for the follow-up slice:

- Provider capability, inventory filter/insight, submission feedback and
  connection insight coverage — 28 tests passed, 172 assertions.
- The browser asset runner was started with PHP 8.5.10; its previous run
  exposed and corrected the legacy `text-primary` and login-input color
  collisions. A fresh run is required after the final CSS build.

## Slice 2 — dashboard application surfaces

Status: implemented and verified locally; commit and push are the next handoff
step.

Responsibility problem addressed:

- The dashboard still mixed the legacy BuildPusher visual vocabulary into the
  Signal shell, which made the highest-traffic page feel like a recolored
  page rather than a Signal composition.
- Operational status, provisioning, active deployments, webhooks and command
  summaries used alert-card layouts that consumed too much mobile space and
  made unrelated operational states look equally urgent.
- Dashboard statistics and trend visualizations did not use Signal's actual
  stat, chart, progress and timeline primitives.

Signal implementation:

- Dashboard hero, setup, overview, provider health and activity surfaces now
  use `ui-panel`, `ui-card`, `ui-eyebrow`, `ui-link`, `text-ink`, `text-muted`
  and Signal's surface tokens.
- Workspace totals use the Signal stat typography with a mobile-specific
  compact geometry that preserves the existing under-170px layout contract.
- Deployment and health trends use Signal's `ui-chart`/`ui-chart-bar`
  primitives; plan and setup capacity use `ui-progress`.
- Active deployments use a Signal `ui-timeline` while retaining every existing
  route, modal trigger, row limit and status count.
- Existing alert variants now retain semantic colored borders on quiet Signal
  surfaces instead of saturated full-card backgrounds.
- Existing modal hooks, dashboard ordering, authorization-scoped data, secret
  redaction and non-JavaScript links remain unchanged.

Evidence:

- `php artisan view:cache` — passed.
- `npm run build` — passed.
- `php artisan test tests/Feature/DashboardTest.php tests/Feature/LocalUiAssetTest.php --do-not-record-test-run-history` — 52 passed, 800 assertions.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.
- `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npx playwright test tests/Browser/asset-layout.spec.js --grep='light at 320px' --reporter=line` — 1 passed.

The browser evidence is for the isolated local fixture runtime only. It is not
live deployment or cloud acceptance.

Next task: commit and push this dashboard slice, then migrate the next
high-traffic resource page using the same actual Signal primitives while
preserving its existing routes, filters, modal contracts and query behavior.
