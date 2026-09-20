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
| 1. Shared mobile shell | Shell owns safe-area, keyboard and mobile document-flow behavior; page views remain unchanged. | Complete | 50 PHP tests / 763 assertions; 3 bounded browser journeys; Pint and Vite passed. | Pending push |

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

Implementation commit: pending.

### Exact next task

Implement Slice 2: make the existing mobile dialogs, filters and long forms
behave as consistent bottom sheets with keyboard-safe action areas, without
changing their URLs, validation contracts or no-JavaScript fallbacks.

## Exact next task

Finish and verify Slice 1, commit and push it, then inspect mobile dialogs and
long-form interactions for Slice 2.
