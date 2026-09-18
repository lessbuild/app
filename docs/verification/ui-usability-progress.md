# UI usability progress

## Purpose

This ledger records the implementation of `docs/ui-usability-plan-2026-09-18.md`.
Each slice must preserve routes, response formats, authorization, validation
keys and error bags, flash messages, Livewire behavior, no-JavaScript paths,
queued-work contracts and persisted values. A slice is complete only after its
focused checks pass, the result is documented and the cohesive commit is pushed
to `origin/main`.

## Phase 0 — inventory and fresh baseline

### Scope and isolation

- Repository branch: isolated checkout on `main` at the start of this work.
- Implementation checkout:
  `/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-preview-cleanup-Amr47o`
- Dev runtime checkout:
  `/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`
- Required PHP: `/root/.local/share/buildpusher/php-8.5.10/bin/php`.
- The implementation checkout uses its own locked dependencies/assets. The
  served runtime has an independent local database, storage, cache, key and
  queue. No production or acceptance-drill checkout is used for this work.
- The runtime paths were verified before Artisan checks. No credentials or
  cloud resources were changed.

### Current page-family inventory

The route/state inventory covers public/authentication, dashboard and project
delivery, environments/configuration, deployments, repositories, websites,
servers and provisioning, providers, databases/domains/load balancers,
backups/restores, observability/incidents/status, notifications/activity/
commands/automation, templates/gallery/reports/feedback, workspace/billing/
costs, account/security, search, documentation and administration. The plan's
full family matrix and evidence are in `docs/ui-usability-plan-2026-09-18.md`.

The current audit rendered 67 route URLs at 390x844 and 1440x1000 (134 visits),
with HTTP responses below 400 and no page-level horizontal overflow. This is
route-rendering evidence, not complete role, modal, mutation or physical-device
coverage. Empty, typical, long-history, pending, failure, entitlement and
multi-role states remain explicit follow-up fixtures.

### Fresh baseline observations

Measured against the isolated development deployment on 2026-09-18:

| Surface | Mobile height | First useful-content problem |
| --- | ---: | --- |
| Observability | 12,629px | Server telemetry starts near 5,560px; alert destinations near 9,484px. |
| Notifications | 9,569px | First notification starts near 1,824px. |
| Dashboard | 7,280px | Needs attention starts near 4,469px. |
| Failed deployment | 6,492px | Recovery guidance starts near 5,542px; logs near 6,007px. |
| Active server detail | 5,002px | Multiple secondary panels precede diagnostics and history. |
| Account | 4,791px | Profile, security, sessions, connections and deletion share one page. |
| Repository detail | 4,446px | Deployment history starts near 3,955px. |
| Deployment history | 3,246px | Eleven filter controls precede statistics and results. |
| Provider creation | 2,207px | Seven stacked provider choices push the token field near 1,055px. |
| Backups | 2,105px | Summary cards consume most of the first screen before history. |

Additional baseline findings:

- Dark secondary text and input text, light secondary text, dark primary button
  text and the footer need a contrast correction. The measured examples were
  approximately 3.04:1, 2.13:1, 2.54:1 and black text on a dark surface.
- The desktop sidebar is internally scrollable and contains about 1,548px of
  content in a 1,000px viewport. The mobile menu intentionally remains the
  restored flat direct-link layout and must not lose its links.
- The command palette arrow-key state does not currently move focus or expose
  an active result. This is a concrete shared-shell accessibility defect.
- Sampled application pages commonly use the generic `BuildPusher` browser
  title. Workspace nesting, duplicate detail sections and large metric blocks
  are documented in the plan for later slices.

### Verification status

The fresh quality baseline completed before the first implementation commit:

- Strict PHP 8.5.10 suite: **1,566 tests / 12,908 assertions**, with no
  failures, warnings, risky tests or deprecations.
- `vendor/bin/pint --test` and `git diff --check`: passed.
- `npm run test:assets`: **9 passed**, including light/dark responsive layouts
  and the no-JavaScript provider submission.
- The authenticated dev-domain browser run completed all three accessibility
  and all three navigation cases. The broad visual crawl was interrupted after
  14.3 minutes while the restored flat mobile menu test waited for the stale
  `Account and security` link; it did not reach the route crawl. This is a
  browser-spec mismatch, not evidence that the mobile menu should be changed.
  The navigation spec's direct `Account`/`Settings` contract is the chosen
  behavior and will be reconciled before the next broad crawl.
- `npm run build` passed. The served dev runtime remained HTTP 200 and was not
  mutated by the baseline.

Historical UI-modernization totals are context only and are not reused as this
baseline.

| Slice | Status | Tests / evidence | Commit / push | Exact next task |
| --- | --- | --- | --- | --- |
| Phase 0: inventory and fresh baseline | Complete | 1,566 PHP tests / 12,908 assertions; Pint; diff check; 9 asset tests; 6 accessibility/navigation tests; broad crawl limitation documented above | Pending | Reconcile the stale visual-audit mobile navigation expectation, then implement the shared readability and keyboard-navigation slice |

Known limitations retained from earlier work: the separate live acceptance
drill, production release gates, physical-phone checks and any external
provider acceptance remain outside this UI implementation.
