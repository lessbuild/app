# UI redesign progress — 2026-09-19

## Working agreement

The redesign is being implemented on `main` in the isolated BuildPusher
checkout. Each cohesive presentation slice is tested, committed and pushed
before the next slice begins. The production checkout and acceptance-drill
checkout are outside the scope of this work.

The restored flat mobile navigation, text-based provider selectors,
border-only semantic alerts and `DEPLOYMENT TIMELINE` presentation are
compatibility decisions. They must remain unchanged unless a separate design
decision is recorded.

## Phase 0 — fresh baseline

Status: baseline recorded; implementation slices have not started.

Current checkout:

- Branch: `main`
- Baseline commit: `def7052`
- Working tree: clean before this ledger entry
- Required PHP: `/root/.local/share/buildpusher/php-8.5.10/bin/php`

Fresh strict PHP baseline:

- **1,606 tests passed / 13,312 assertions**
- Duration: **594.33 seconds**
- No failures, warnings, risky tests, deprecations or PHPUnit deprecations

The authenticated development dashboard was inspected at desktop and mobile
sizes alongside the authorized reference dashboard at
`http://174.138.39.41:8004/dashboard`. The reference supplied the visual
inspiration for a calm neutral canvas, narrow grouped navigation, a first-value
checklist, quick actions, compact metrics and attention-first cards. Its
business content is not being copied into BuildPusher.

Current BuildPusher evidence from the development fixture:

- Dashboard page height: approximately 4,561px at 1440px wide.
- Dashboard page height: approximately 6,380px at 390px wide.
- The dashboard already uses bounded, organization-scoped reads for setup,
  attention, deployments, health, providers, commands and activity.
- The existing mobile menu remains a flat direct-link menu by design.

## Slice ledger

### Slice 1 — dashboard first-value hierarchy

- User problem: the dashboard’s welcome area and totals were visually dense,
  while primary actions were only available in the header and the four totals
  used an older icon-led treatment.
- Entry point: `resources/views/dashboard.blade.php` and its dashboard
  presentation partials.
- Boundary: added `_quick-actions.blade.php` and `_metrics.blade.php` as
  presentation-only partials; added shared dashboard surface classes in
  `resources/css/components/ui.css`. No controller, query, policy, route or
  persistence behavior changed.
- SOLID/Laravel rationale: the view now has cohesive presentation
  responsibilities without expanding `DashboardController`; existing bounded
  data is consumed directly and the established `ui-stat`/`ui-card` components
  are reused.
- Preserved contracts: attention remains before setup, setup remains before
  operational metrics, existing route targets and dashboard preferences remain
  unchanged, and the 320px metric height contract is maintained by hiding
  secondary metric descriptions at the narrowest breakpoint.
- Verification: `DashboardTest` — 25 tests / 258 assertions; Pint passed;
  `npm run build` passed; the 320px light asset-layout browser case passed;
  `git diff --check` passed. The first browser attempt used the wrong default
  PHP 8.3 binary and was rerun successfully with the required PHP 8.5 binary.
- Commit and push: `5ab1155 Improve dashboard first-value hierarchy`, pushed
  to `origin/main`.
- Next task: establish the shared resource-page header and local-navigation
  treatment, beginning with the page-family inventory and a low-risk reusable
  presentation component.

### Slice 2 — shared resource headers and local navigation

- User problem: resource pages used a shared component, but its visual
  treatment did not provide enough context or a consistent compact way to move
  between long page sections on small screens.
- Entry points: `x-ui.page-header`, the layout heading partial, major resource
  index views, and the Observability and Automation section links.
- Boundary: added an optional contextual eyebrow to the shared page-header
  component, a reusable `x-ui.local-nav` component, and semantic responsive
  styles for both. Applied the eyebrow to Applications, Servers, Websites,
  Repositories, Providers, Deployments, Backups, Observability, Databases,
  Domains, Costs, Automation and Activity. Converted the two existing section
  link rows to the shared local-nav component.
- SOLID/Laravel rationale: repeated presentation responsibilities are owned by
  reusable Blade components and CSS rather than copied into controllers or
  individual pages; page-specific actions remain slots and existing route
  semantics remain page-owned.
- Preserved contracts: no route, query, authorization, form, persistence or
  response behavior changed. Existing page titles, descriptions, actions and
  anchor targets remain intact. Local navigation is keyboard-focusable and
  horizontally scrollable on narrow screens.
- Verification: 45 focused dashboard/local-UI tests passed with 670
  assertions; 36 focused insights/observability/search tests passed with 340
  assertions; Blade view cache passed; Pint passed; Vite build passed; light
  and dark asset-layout fixtures passed at 390px and 1440px; `git diff --check`
  passed.
- Commit and push: `e20ea89 Unify resource page headers`, pushed to
  `origin/main`.
- Next task: polish the deployment, infrastructure and recovery page family,
  starting with long list pages and their mobile filter/action hierarchy.

### Slice 3 — deployment and infrastructure inventory surfaces

- User problem: long deployment and infrastructure pages needed clearer local
  orientation and a more consistent visual response for inventory rows, while
  existing filter disclosure behavior was already covered by compatibility
  tests.
- Entry points: Deployment history, Servers, Websites and Repositories index
  views, plus the shared heading partial and UI component styles.
- Boundary: added a compact deployment local-nav linking filters, overview and
  history; added a shared inventory-list affordance for hover/focus context;
  and corrected the shared heading partial to declare its existing title,
  description and icon inputs alongside the new optional eyebrow.
- SOLID/Laravel rationale: presentation repetition is handled in shared Blade
  and CSS boundaries; inventory data, filtering, authorization and route
  actions remain owned by their existing controllers, requests and queries.
- Preserved contracts: existing filter semantics and server-rendered open
  state are unchanged. The attempted responsive-details conversion for filter
  panels was withdrawn after focused tests showed an active-filter rendering
  regression; it remains a separately characterized follow-up rather than an
  unverified behavior change.
- Verification: 49 focused tests passed with 661 assertions; Pint passed;
  Vite build passed; the light 390px asset-layout fixture passed; `git
  diff --check` passed.
- Commit and push: `788d7fe Polish deployment inventory surfaces`, pushed to
  `origin/main`.
- Next task: improve operational, automation, account and public surfaces,
  beginning with the shared insight/disclosure hierarchy on Observability,
  Automation and Commands.

### Slice 4 — operational, account and feedback surfaces

- User problem: Commands, Notifications, Feedback, System Health, Account and
  Workspace pages remained tall, multi-purpose screens without a compact way
  to jump between their primary sections. Notification cards also used filled
  status backgrounds, which competed with the content and differed from the
  established border-only alert language.
- Entry points: the Commands, Notifications, Feedback, System Health, Account
  and Workspace Blade views.
- Boundary: applied the shared contextual page-header eyebrows and local-nav
  component to each surface; added stable scroll targets for the major
  sections; marked long inventory lists for the shared row treatment; and
  changed unread notification emphasis to a colored leading border only.
  No controller, query, policy, route, form, persistence or notification
  behavior changed.
- SOLID/Laravel rationale: repeated presentation concerns are kept in the
  shared Blade/CSS components while each page retains ownership of its
  content and existing actions. The navigation is progressive enhancement:
  ordinary anchors remain usable without JavaScript and do not introduce a
  new client-side state boundary.
- Preserved contracts: all existing form actions, validation, named error
  bags, filters, pagination, authorization and disclosure open-state logic
  remain unchanged. Status remains visible through badges and the semantic
  border, without a full-surface color fill.
- Verification: 82 focused tests passed with 908 assertions across local UI,
  insights, commands, notifications, feedback, system health, account and
  organization coverage; Pint passed; Vite build passed; `git diff --check`
  passed.
- Commit and push: `5c469fc Polish operational and account surfaces`, pushed
  to `origin/main`.
- Next task: give the public landing and authentication pages the same calm
  hierarchy, while preserving their truthful content, metadata, routes and
  non-JavaScript navigation.

### Slice 5 — public landing and authentication surfaces

- User problem: the public landing page and authentication screens used
  functional but visually separate surfaces, so the product promise and the
  signed-out entry point did not share the calm workspace hierarchy used in
  the authenticated application.
- Entry points: the public landing page, the shared authentication layout and
  the shared UI stylesheet.
- Boundary: added semantic presentation hooks for the public header, landing
  hero, provider strip, CTA and authentication shell; added responsive
  gradients, a subtle grid texture, restrained elevation and mobile spacing
  through the shared UI stylesheet. No copy claims, metadata, route, form,
  authorization or authentication behavior changed.
- SOLID/Laravel rationale: visual behavior lives in the shared layout and
  component stylesheet instead of being duplicated across login, register and
  password screens. The existing Blade layout remains the single composition
  boundary for authentication pages.
- Preserved contracts: landing anchors, no-JavaScript navigation, SEO/share
  metadata, illustrative-data disclosures, login form fields and all existing
  auth layout accessibility hooks remain unchanged.
- Verification: 47 focused tests passed with 706 assertions; Pint passed;
  Vite build passed; light 320px and 1440px asset-layout browser fixtures
  passed; `git diff --check` passed.
- Commit and push: `85f8fc1 Refine public and authentication surfaces`,
  pushed to `origin/main`.
- Next task: run the responsive/accessibility sweep across the complete
  product route inventory, fix only evidenced visual regressions, then run
  the full regression and browser verification gates.

### Slice 6 — semantic alert compatibility and final release gate

- User problem: the intentional border-only alert treatment changed the
  rendered utility classes used by notification, health and account status
  surfaces, so the existing compatibility assertions needed to describe the
  new visual contract precisely.
- Entry points: border-only notification/health/account presentation and the
  corresponding feature assertions.
- Boundary: retained the application change as presentation-only and aligned
  five existing assertions with the concrete `border-l-*` classes. No
  controller, query, policy, route, form, persistence or workflow behavior
  changed.
- SOLID/Laravel rationale: semantic status styling remains a shared UI
  concern; the tests verify the rendered contract without moving business
  state or authorization into the view layer.
- Preserved contracts: status badges, alert meaning, flash text, response
  behavior, no-JavaScript flows and all existing notification/health
  semantics remain unchanged.
- Verification: the focused compatibility set passed **36 tests / 504
  assertions**. The complete strict PHP 8.5.10 suite passed **1,609 tests /
  13,362 assertions** in **585.57 seconds**, with no failures, warnings,
  risky tests, deprecations or PHPUnit deprecations. Required-PHP Pint,
  Vite, Composer platform checks, route listing and `git diff --check` all
  passed.
- Commit and push: `1dd8d3e Align alert style regression assertions`,
  pushed to `origin/main`.
- Next task: complete the documentation handoff and leave production/live
  acceptance as a separately authorized release activity.

## Final verification — 2026-09-19

The local UI redesign plan is complete on isolated `main`. The implementation
covered the dashboard first-value hierarchy, shared resource headers and
local navigation, deployment/infrastructure inventory surfaces, operational
and account pages, public landing/authentication surfaces, and the final
semantic alert compatibility contract. Existing mobile navigation, text-based
provider selectors and the `DEPLOYMENT TIMELINE` presentation were retained.

The final disposable browser runtime used a temporary file-backed SQLite
database, seeded demo records, file sessions, synchronous queues and an
independent application key. It was stopped after verification. A fresh
route-cache runtime served `/login` with HTTP 200 and the generated Livewire
asset with HTTP 200. The Laravel development server was run with
`--no-reload` because its reload mode intentionally strips temporary
environment overrides from the child process; this was an isolation harness
detail, not an application behavior change.

Browser evidence:

- The complete built-asset layout and provider fallback suite passed **10
  tests** in **6.2 minutes** across light/dark 320px, 390px, 768px and
  1440px viewports.
- The authenticated navigation/accessibility sweep passed **6 tests** and
  the broad mobile/tablet/desktop route audit passed **3 tests** against the
  isolated runtime. The route audit found no runtime errors or horizontal
  overflow.
- The earlier mobile/tablet/desktop Livewire/public-navigation smoke passed
  **1 test**; the selected visual audit cases passed at mobile, tablet and
  desktop widths.

The checkout is clean and `main` is aligned with `origin/main` at the source
commit above before this documentation follow-up. No production checkout,
acceptance-drill checkout, credentials, paid cloud resource or live provider
acceptance was used. Production release, physical-device checks, provider
acceptance, mail, billing, GitHub App, independent monitoring and recovery
drills remain external release gates.

## Remaining sequence

1. External/live acceptance when separately authorized.
