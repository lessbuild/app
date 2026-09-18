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

## Remaining sequence

1. Dashboard hierarchy and first-value experience.
2. Shared shell, resource headers and local navigation.
3. Deployment, infrastructure and recovery page-family polish.
4. Operational, automation, account and public-surface polish.
5. Responsive accessibility and complete regression verification.
