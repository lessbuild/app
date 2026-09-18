# UI redesign progress — 2026-09-18

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

## Remaining sequence

1. Dashboard hierarchy and first-value experience.
2. Shared shell, resource headers and local navigation.
3. Deployment, infrastructure and recovery page-family polish.
4. Operational, automation, account and public-surface polish.
5. Responsive accessibility and complete regression verification.
