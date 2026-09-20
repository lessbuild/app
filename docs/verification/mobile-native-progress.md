# Mobile-native UX modernization progress

## Scope

Make the authenticated BuildPusher experience feel fast and native on small
screens without changing routes, permissions, validation contracts, queued
work, modal URLs or no-JavaScript fallbacks.

The implementation is being completed as small slices on isolated `main`.
Each verified slice is committed and pushed before the next slice starts.

## Baseline — 2026-09-20

- Checkout: isolated `main` at `7a017fa`.
- Working tree: clean.
- Runtime: PHP 8.5.10 at `/root/.local/share/buildpusher/php-8.5.10/bin/php`.
- Focused PHP baseline: **38 tests / 558 assertions passed**.
- Pint: passed.
- Vite production build: passed.
- `git diff --check`: passed.
- Existing browser coverage already covers 320/390/768/1440px fixture
  layouts, authenticated navigation, dialogs, focus restoration, mobile
  quick actions and no-JavaScript provider submission.
- No production, cloud or external-device acceptance is claimed.

## Slice ledger

| Slice | Responsibility boundary | Status | Verification | Commit / push |
| --- | --- | --- | --- | --- |
| 1. Shared mobile shell | Shell owns safe-area, keyboard and mobile document-flow behavior; page views remain unchanged. | Complete | 50 PHP tests / 763 assertions; 3 bounded browser journeys; Pint and Vite passed. | `85ef0fe` pushed |
| 2. Mobile sheets and filters | Shared dialog and filter components own mobile sheet geometry, safe-area action space and dismissal behavior. | Complete | 45 PHP tests / 612 assertions; 2 focused browser journeys; Pint and Vite passed. | `57da0b8` pushed |
| 3. Mobile page hierarchy | Shared page headers, dashboard actions and inventory cards own compact mobile spacing; data and routes remain page-owned. | Complete | 53 PHP tests / 785 assertions; 1 full 320px fixture case; Pint and Vite passed. | Pending push |

## Preserved contracts

- Existing mobile navigation links and labels remain available.
- The four quick actions retain their routes, modal query state and accessible
  names.
- Native dialog history, Escape handling and focus restoration remain intact.
- Existing Form Requests, policies, actions, Livewire updates and queued work
  are outside the first UI slice.

## Slice 1 — shared mobile shell

### Responsibility problem

The authenticated layout reserved mobile space in multiple places, kept the
desktop footer in the mobile document flow and had no way to respond when the
mobile keyboard covered the bottom quick actions. These are shell concerns and
should not be solved independently in every page.

### Boundary and design decision

The authenticated layout now exposes semantic mobile-shell hooks. The shell
owns the safe-area budget, mobile content scroll padding and keyboard state;
the fixed quick-action navigation retreats while an editable control has the
mobile keyboard open. The mobile footer is desktop-only because its links
remain available through the mobile navigation. Existing navigation labels,
routes, modal query state and focus behavior are unchanged.

The implementation uses existing Blade/Alpine markup and a small native
`visualViewport` listener. It does not add a dependency, change persistence or
move application logic into the browser.

### Verification

- Focused PHP regression: **50 tests / 763 assertions passed**.
- Pint: passed.
- Vite production build: passed.
- `git diff --check`: passed.
- Bounded browser verification with PHP 8.5.10: **3 passed** for dashboard
  page-local dialogs, mobile New app same-page behavior and the 320px
  responsive shell case.
- The complete 26-case asset run was attempted with the required PHP binary.
  Its fixture worker completed the dialog and responsive cases reached before
  hanging in the provider no-JavaScript fixture for more than 17 minutes; the
  process was stopped. This is recorded as a test-harness limitation, not as
  full-suite passing evidence.

### Commit and push

Implementation commit and push: `85ef0fe Improve mobile shell behavior`.

## Slice 2 — mobile sheets and filters

### Responsibility problem

Dialogs and filters used different mobile presentation rules. Long dialog
content could scroll independently without a shared safe-area/action contract,
and filter disclosures did not provide a native-feeling mobile dismissal path.

### Boundary and design decision

The shared dialog component now exposes sheet, panel, header and body hooks.
The shared CSS owns mobile bottom-sheet geometry, the visual handle, sticky
headers, sticky bordered action rows, safe-area padding and contained scrolling.
The shared filter component owns its mobile sheet state and backdrop; a small
core-layout listener closes it through Escape or the backdrop and returns focus
to the filter summary.

No individual feature form was rewritten. Existing dialog URLs, browser
history, validation fields, no-JavaScript fallbacks and filter query behavior
remain unchanged.

### Verification

- Focused PHP regression: **45 tests / 612 assertions passed**.
- Pint: passed.
- Vite production build: passed.
- `git diff --check`: passed.
- Focused browser verification with PHP 8.5.10: **2 passed** for mobile
  provider-sheet geometry and dismissible repository filters.

### Commit and push

Implementation commit and push: pending.

## Slice 3 — mobile page hierarchy

### Responsibility problem

Shared page headers and dashboard action groups used desktop-sized spacing on
small screens, while application cards retained a large minimum height. This
made common inventory and dashboard journeys longer without adding information.

### Boundary and design decision

The shared UI component stylesheet now owns compact mobile page-header spacing,
two-column action layout, two-line description clamping, dashboard hero and
quick-action density, and compact inventory list rows. The application card
view uses responsive utility classes so its mobile and desktop sizing is
explicit in the view contract.

No query, pagination, authorization, resource data or action placement was
changed. Desktop layout remains unchanged at the existing breakpoint.

### Verification

- Focused PHP regression: **53 tests / 785 assertions passed**.
- Pint: passed.
- Vite production build: passed.
- `git diff --check`: passed.
- Full 320px built-asset fixture case: **1 passed**, including page-header
  action layout, compact application card sizing, navigation, dialogs and
  mobile overflow checks.

### Exact next task

Implement Slice 4: standardize mobile loading, empty, error and queued-operation
feedback so long-running BuildPusher actions feel native without changing job
semantics or optimistic-state guarantees.
