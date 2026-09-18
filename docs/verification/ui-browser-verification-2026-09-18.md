# UI browser verification — 2026-09-18

## Scope

This record closes the browser-verification follow-up for the UI usability
plan. It uses the isolated `main` checkout and the disposable runtime only:

- Implementation checkout: `/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-preview-cleanup-Amr47o`
- Runtime checkout: `/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`
- Runtime origin: `http://127.0.0.1:8010`
- PHP: `/root/.local/share/buildpusher/php-8.5.10/bin/php`

The runtime's local `APP_URL`, `ASSET_URL` and secure-cookie setting were
corrected for the disposable HTTP origin only. No repository `.env`, live
environment, production checkout or acceptance-drill checkout was changed.

## Safe disk cleanup

Before browser verification the root filesystem had 821 MB available. The
read-only audit identified the following disposable data:

- Composer cache: approximately 70 MB.
- npm cache: approximately 49 MB.
- Task-owned Playwright/layout/browser-profile temporary directories: 36 MB.

The installed Playwright Chromium binaries, repositories, runtime database,
storage, credentials and desktop trash were retained. Clearing the
regenerable Composer/npm caches and task-owned `/tmp` artifacts left 972 MB
available. The temporary directories are absent and the caches are now
negligible.

## Commands and results

All browser specs were run with one worker against the pinned PHP/runtime:

| Command | Result |
| --- | --- |
| `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npm run test:assets` | **9 passed** in 2.1 minutes |
| `BROWSER_LIVE_ORIGIN=http://127.0.0.1:8010 npx playwright test tests/Browser/live-runtime.spec.js` | **1 passed** in 40.6 seconds |
| `BROWSER_BASE_URL=http://127.0.0.1:8010 BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npx playwright test tests/Browser/navigation.spec.js tests/Browser/accessibility.spec.js` | **6 passed** in 1.2 minutes |
| `BROWSER_BASE_URL=http://127.0.0.1:8010 BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npx playwright test tests/Browser/visual-audit.spec.js` | **3 passed** in 8.4 minutes |

The 19 passing tests cover built light/dark layouts at 320/390/768/1440px,
mobile menu open/close, Escape and focus restoration, command-palette
keyboard selection, no-JavaScript provider submission, served Livewire assets,
public mobile navigation, authenticated navigation at mobile/tablet/desktop,
accessibility labels and focus, and the broad authenticated route crawler.
The crawler reported no page errors or horizontal overflow.

## Outcome

The previous browser follow-up is complete locally. No browser regression or
remaining tablet/mobile navigation expectation was found in the current
`main` line. Production release, physical-phone checks, live acceptance and
external provider acceptance remain separate gates.

## Final isolated-main confirmation — 2026-09-18

After the remaining UI slices, the final implementation checkout was verified
with its own disposable runtime on port 8014, a temporary SQLite database and
array/file-backed local services. The runtime was stopped after the checks.
The served runtime was the isolated implementation checkout, not the live
site, production checkout or acceptance-drill checkout.

The current final code passed:

- **10** asset/layout fixture tests across light/dark 320/390/768/1440px,
  including the dashboard, deployment, provider, backup, observability and
  automation hierarchy checks.
- **7** served Livewire, public-navigation, authenticated-navigation and
  accessibility tests.
- **3** broad mobile/tablet/desktop visual route-audit tests.

This is **20 final browser tests passed**. The route audit reported no runtime
errors or horizontal overflow. The earlier 19-test browser follow-up remains
the historical record for that earlier code point; this section records the
current final implementation. Production release, physical-device checks,
live acceptance and external provider acceptance remain separate gates.
