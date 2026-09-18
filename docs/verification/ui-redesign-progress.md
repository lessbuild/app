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

No redesign implementation slice has been completed yet.

## Remaining sequence

1. Dashboard hierarchy and first-value experience.
2. Shared shell, resource headers and local navigation.
3. Deployment, infrastructure and recovery page-family polish.
4. Operational, automation, account and public-surface polish.
5. Responsive accessibility and complete regression verification.
