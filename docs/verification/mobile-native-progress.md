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
| 3. Mobile page hierarchy | Shared page headers, dashboard actions and inventory cards own compact mobile spacing; data and routes remain page-owned. | Complete | 53 PHP tests / 785 assertions; 1 full 320px fixture case; Pint and Vite passed. | `56ea637` pushed |
| 4. Mobile feedback states | Shared feedback primitives and the authenticated shell own compact alerts, empty states and connectivity recovery messaging; operation state remains server-owned. | Complete | 51 PHP tests / 774 assertions; 1 focused browser journey; Pint, Vite and diff check passed. | Pending push |
| 5. Mobile form affordances | Shared form feedback and modal loading styles own focus-safe mobile presentation; validation contracts and server operation state remain unchanged. | Complete | 30 PHP tests / 503 assertions; 1 focused browser journey; Pint, Vite and diff check passed. | Pending push |

## Preserved contracts

- Existing mobile navigation links and labels remain available.
- The four quick actions retain their routes, modal query state and accessible
  names.
- Native dialog history, Escape handling and focus restoration remain intact.
- Existing Form Requests, policies, actions, Livewire updates and queued work
  are outside the first UI slice.
- Feedback notices are additive and do not change queued-operation state,
  retry behavior, persistence or authorization.

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

Implementation commit and push: `57da0b8 Make mobile dialogs and filters native`.

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

## Slice 4 — mobile feedback states

### Responsibility problem

Mobile users received feedback through several existing primitives, but the
shell had no shared way to present connectivity loss and feedback surfaces did
not expose a consistent semantic hook for compact mobile treatment. This made
offline interruptions and long empty/error states harder to understand without
changing the underlying operation state.

### Boundary and design decision

The shared alert, flash and empty-state components now expose feedback hooks.
The authenticated shell owns the connection-status notice, including its
safe-area position above the mobile quick actions and its temporary
reconnected message. Existing server-rendered queued, loading and failure
messages remain authoritative; the client does not invent optimistic operation
state or retry jobs.

The mobile stylesheet compacts repeated feedback surfaces and clamps long
empty-state descriptions. The network listener is native browser behavior and
does not add a dependency or change routes, requests, persistence or
authorization.

### Verification

- Focused PHP regression: **51 tests / 774 assertions passed**.
- Pint: passed.
- Vite production build: passed.
- `git diff --check`: passed.
- Focused browser verification with PHP 8.5.10: **1 passed** for offline,
  reconnected and mobile quick-action-safe feedback behavior.

### Exact next task

Implement Slice 5: harden mobile form focus, validation visibility and loading
affordances without changing validation keys, named error bags or no-JavaScript
submission behavior.

## Slice 5 — mobile form affordances

### Responsibility problem

The shared shell already prevented narrow controls from triggering browser zoom,
but focused fields did not declare a header-safe scroll margin and lazy dialog
content had no visible busy affordance. Field-level errors and the provider
validation summary also lacked a consistent hook for compact mobile treatment.

### Boundary and design decision

The shared form-error component exposes field-error hooks, while the provider's
existing summary exposes a summary hook without changing its text, focus order,
error bag or secret-safe behavior. The global mobile stylesheet owns control
scroll margins, compact error sizing and a reduced-motion-aware busy indicator
for the existing `aria-busy` modal-content lifecycle.

No request validation, route, named error bag, persisted value, queued job or
no-JavaScript form path changed. The busy indicator reflects the existing lazy
content request and does not create a new retry or optimistic state.

### Verification

- Focused PHP regression: **30 tests / 503 assertions passed**.
- Pint: passed.
- Vite production build: passed.
- `git diff --check`: passed.
- Focused browser verification with PHP 8.5.10: **1 passed** for 16px mobile
  controls, modal-safe scroll margins and lazy-content busy-state feedback.

### Exact next task

Run the final mobile-native verification slice: refresh the full focused PHP and
asset checks, audit responsive regressions, update the handoff and record
external-device acceptance as outstanding rather than claiming it locally.
