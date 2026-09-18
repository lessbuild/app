# UI experience modernization progress — 2026-09-18

## Working agreement

Implementation is being carried out on `main` in the isolated checkout with
independent dependencies, storage, cache, application key and test database.
The served development runtime and acceptance-drill checkout are not used as
write targets during local implementation. Each verified cohesive slice is
committed and pushed before the next slice begins.

The flat mobile navigation and text-based provider selectors are intentional
compatibility constraints.

## Phase 0 — inventory and baseline

Status: inventory reproduced; fresh complete-suite baseline recorded.

The 2026-09-18 rendered review covered public pages, authentication, dashboard,
applications, deployments, repositories, websites, servers, providers,
backups, observability, automation, notifications, workspace/account,
templates, reports, costs and representative resource details at 390 × 844
and 1440 × 1000, with tablet and light-theme checks. It found no document-wide
horizontal overflow, but did find internal clipping, excessive task distance,
light-theme contrast problems and inaccessible closed desktop disclosures.

The first verified measurements are recorded in the Luna Max plan and include:

- Dashboard attention content beginning around 1,681px on the mobile fixture.
- Provider credential input beginning around 1,237px.
- Notifications results beginning around 1,093px, with filters near the end.
- Observability telemetry beginning around 4,062px after incident cards.
- Application count badges clipping inside otherwise non-overflowing cards.

This is local rendered evidence only. Physical-device, cloud and live
acceptance remain outstanding.

### Fresh isolated baseline

The required-PHP strict suite was run after the responsive-disclosure slice in
the isolated checkout:

- **1,602 tests passed / 13,173 assertions**.
- Duration: 652.37 seconds.
- No warnings, risky tests, deprecations or PHPUnit deprecations were reported.

This establishes the current local regression baseline; it does not establish
browser, physical-device, cloud or live acceptance.

## Slice 1 — responsive disclosure accessibility

Status: verified; pushed in the implementation commit for this slice.

### Concrete problem

The shared `forms.section` component and explicit workspace/API-token panels
used native closed `<details>` elements while hiding their `<summary>` at the
desktop breakpoint. Utility classes on the children could not override the
native closed-details layout, so desktop users could not reach security,
workspace, account or token controls.

### Boundaries and principle

The presentation component owns responsive disclosure state and the shared
layout owns its breakpoint synchronization. This is single responsibility: the
HTTP, authorization and persistence code remains unchanged. Native `<details>`
semantics remain the interaction contract, with progressive enhancement only
changing the initial mobile state.

### Implementation

- Added a shared responsive-details marker to `x-forms.section`.
- Applied the same contract to workspace deletion and personal access tokens.
- Rendered the desktop state open so desktop and no-JavaScript users retain
  access to the content.
- Added a small layout-level synchronizer that restores the existing collapsed
  mobile default unless validation or a one-time token display requires the
  panel to be open.
- Preserved validation-driven open state, all IDs, routes, form fields,
  permissions and token handling.
- Added real organization and automation pages to the isolated asset fixture.

### Verification

- Organization, account and automation feature coverage: **71 tests / 403
  assertions**.
- Isolated fixture renderer: **1 test / 16 assertions**.
- Built asset/layout browser matrix, light/dark at 320/390/768/1440px,
  including mobile collapse and desktop visibility: **9 passed**.
- Provider no-JavaScript submission regression remained passing.
- Required-PHP Pint and `git diff --check`: passed.

### Commit and push

Implementation commit `c897cf9` was pushed to `origin/main` before the next
slice begins. This ledger update records the exact verification handoff.

## Slice 2 — theme contrast and application-card containment

Status: verified; implementation committed and pushed.

### Concrete problem

The authenticated mobile header used the light-theme `text-primary` token on a
fixed dark utility bar, producing a low-contrast dark brand name. Application
cards also placed a non-wrapping environment-count badge beside a flexible
name block without a wrapping boundary. At narrow widths, long application
names could force the badge outside the card or clip its text even when the
document itself had no horizontal overflow.

### Boundaries and principle

The layout templates own presentation constraints, so this slice stays within
the view/CSS boundary and does not alter controllers, policies, persistence or
routes. The header now uses the foreground token that matches its deliberately
dark surface. The card header explicitly separates a shrinkable name region
from a non-shrinking badge and permits the badge to move to its own line. This
is single responsibility at the presentation boundary: content and business
data remain unchanged while each component owns its own readable geometry.

### Implementation

- Made the authenticated mobile brand use `text-gray-100` on the fixed dark
  header, independent of the page color scheme.
- Added stable test hooks for the authenticated brand and application card
  count badge.
- Made application-card headers `min-w-0`, flexible and wrapping; the name
  region can shrink and the count badge retains its complete text.
- Added a long-name project to the isolated layout fixture and included the
  real application index in the responsive browser matrix.

### Verification

- Isolated fixture renderer: **1 test / 18 assertions**.
- Local UI feature coverage: **19 tests / 396 assertions**.
- Built asset/layout browser matrix, light/dark at 320/390/768/1440px,
  including computed header contrast, badge text containment and the existing
  provider no-JavaScript journey: **9 passed**.
- `npm run build`, required-PHP Pint and `git diff --check`: passed.

### Commit and push

Implementation commit `2bb0b8b` was pushed to `origin/main`.

## Slice 3 — shared shell and navigation refinement

Status: verified; implementation committed and pushed.

### Concrete problem

The shared page-header action region had no stable hook or touch-size rule,
while the fixed mobile quick-action bar called a link to the application form
just `Create`. That label implied a broader action menu than the link provided,
and the quick actions did not state their 44px touch target in the shell
markup. The existing flat mobile navigation also needed to remain unchanged as
an explicit product constraint.

### Boundaries and principle

The shared page-header and authenticated layout components own shell
presentation and interaction semantics. This is single responsibility: the
navigation model, route names, authorization and mobile destination inventory
were not changed. The flat menu remains intact; only the quick-action label,
active state and shared sizing contract were refined.

### Implementation

- Added a stable `data-ui-page-header-actions` hook and a shared 44px minimum
  height for page-header buttons, including buttons nested in forms.
- Renamed the bottom-bar `Create` destination to `New app`, matching its
  existing application-creation route.
- Added active-state semantics for the `New app` quick action and stable hooks
  for all four quick actions.
- Applied explicit 44px minimum touch targets to the bottom-bar links and
  search button while preserving safe-area padding, Escape/focus handling and
  the old flat menu.

### Verification

- Local UI feature coverage: **19 tests / 399 assertions**.
- Built asset/layout browser matrix, light/dark at 320/390/768/1440px,
  including quick-action label/path/touch-size checks, responsive disclosures,
  application-card geometry and provider no-JavaScript submission: **9 passed**.
- Required-PHP Pint, `git diff --check` and `npm run build`: passed.

### Commit and push

Implementation commit `6a7c364` was pushed to `origin/main`.

## Slice 4 — public preview and pricing hierarchy

Status: verified; implementation committed and pushed.

### Concrete problem

The landing page’s static interface preview used live-sounding labels such as
“Live workspace” and “System operational · 12 checks passing,” which could be
read as current telemetry. Its hero also used more vertical spacing than
necessary on small screens. Pricing rendered every feature and API limit
inside six full-height cards, creating a long mobile scan before a visitor
could compare the next plan.

### Boundaries and principle

The public Blade views own copy hierarchy, responsive presentation and demo
disclosure. Registration availability, pricing data, plans, amounts, routes,
entitlements and checkout targets remain supplied by the existing controller
and configuration. Native `<details>` keeps the complete pricing information
available without JavaScript; the shared responsive-details behavior only
changes the initial mobile presentation.

### Implementation

- Tightened the landing hero’s small-screen spacing while retaining the
  primary registration/access action and product preview near the top.
- Renamed static preview labels to “Illustrative workspace,” “Example” and
  “Example data · not live telemetry,” and removed implementation-focused
  “no screenshots” wording.
- Replaced the six-card mobile feature wall with three visible summary
  features per plan and a native “See all features and limits” disclosure;
  desktop remains expanded and no-JavaScript rendering remains complete.
- Added pricing and public-preview coverage to the isolated responsive fixture
  matrix.

### Verification

- Public UI, pricing and access-request coverage: **42 tests / 528
  assertions**.
- Isolated fixture renderer: **1 test / 19 assertions**.
- Built asset/layout browser matrix, light/dark at 320/390/768/1440px,
  including six-plan disclosure behavior, illustrative-preview copy,
  responsive disclosures, application-card geometry and provider
  no-JavaScript submission: **9 passed**.
- Required-PHP Pint, `git diff --check` and `npm run build`: passed.

### Commit and push

Implementation commit `1b28b12` was pushed to `origin/main`.

### Exact next task

Begin Slice 5: improve the dashboard’s results-first hierarchy and mobile task
distance, preserving onboarding order, attention semantics, query bounds and
all existing authorization/entitlement behavior.

## Remaining planned slices

1. Theme, clipping and misleading-status corrections.
2. Shared visual foundation and navigation refinement.
3. Landing page and pricing hierarchy.
4. Dashboard and results-first lists.
5. Resource and deployment detail hierarchy.
6. Setup forms, beginning with provider creation.
7. Backups, observability and automation hubs.
8. Remaining page families and final responsive/accessibility verification.

Each slice must record its concrete behavior, tests, commit, push status and
next task here before work advances.
