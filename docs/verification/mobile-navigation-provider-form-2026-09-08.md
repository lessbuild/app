# Mobile navigation and provider submission — 2026-09-08

## Cause and repair

The live cached routes still served the old Livewire endpoints, although rendered pages emitted Livewire 4's hashed prefix. A request to the script URL from the actual login HTML returned HTTP 404. That prevented Livewire's bundled Alpine from initializing both the mobile menu and the provider picker's JavaScript-only hidden field.

Backed up the original cache to `/root/.local/share/buildpusher/runtime-backups/2026-09-08/routes-before-mobile-navigation-fix.php`, then ran `php artisan route:cache` with the private PHP 8.5.10 wrapper. The live script now returns HTTP 200 with a JavaScript content type. No source route, database or cloud resource was changed by this cache repair.

The provider form now uses native required radio inputs with visible selection/focus styling. Existing icons, supported provider values, saved edit selection and backend validation are preserved. It can submit the chosen provider without JavaScript. Credentials continue to be encrypted by the model and are never filled back into the form.

## Verification

- The live-runtime Playwright smoke test passes against `https://buildpusher.com`: it fetches the rendered Livewire script through the actual web server, checks its HTTP response/content type, confirms Alpine/Livewire initialization, and exercises the public mobile menu. It performs no form submissions.
- The signed-in dashboard fixture passes menu opening, close button, Escape, focus restoration and scroll lock at 320, 390 and 768 pixels using the live script. The isolated fixture's key-derived URL prefix was mapped to the prefix from the live login page. No live user session or authenticated action was used.
- The provider browser regression passes at 390px with JavaScript disabled, checking the submitted provider value. The request is fulfilled locally; the fixture does not create a live provider or transmit its dummy token.
- `ProviderCapabilityTest`: **8 tests / 49 assertions**, including successful form submission, encrypted token storage and exact edit selection. Database changes use the isolated test database.
- Production asset build, scoped Pint, JavaScript syntax and `git diff --check` pass.

## Deployment regression check

After framework/runtime upgrades, rebuild cached routes using the same environment and PHP version as the web application. Checking only readiness is insufficient: the migration gate can pass while an asset URL still returns 404. The existing layout matrix serves local vendor assets directly and therefore also cannot establish live route-cache correctness.

Run this opt-in, read-only smoke test against the installed application:

```sh
BROWSER_LIVE_ORIGIN=https://buildpusher.com npx playwright test tests/Browser/live-runtime.spec.js
```

Run the isolated provider submission check with a compatible PHP executable:

```sh
BROWSER_PHP_BINARY=/path/to/php npx playwright test tests/Browser/asset-layout.spec.js --grep 'provider creation'
```

Reload an already-open browser page after the runtime repair so its scripts initialize again. The separate DigitalOcean credential preflight still needs a valid saved token; this repair does not claim provider authentication succeeded.
