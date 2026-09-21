# Signal theme integration progress

## Slice 1 — shared theme and application shell

Status: implemented and pushed on `main` through commit `859e898`; the
follow-up compatibility and provider-control slice is currently in progress.

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

Next task: complete the fresh browser verification, commit and push this
follow-up slice, then migrate the dashboard to Signal's application cards,
timeline and responsive data patterns.
