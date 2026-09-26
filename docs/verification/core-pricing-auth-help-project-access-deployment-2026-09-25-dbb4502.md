# Core pricing, Auth, help, and project-access release

Application commit: `dbb450254562c104fcd7d5b9b999e208153258cf`

Branch: `feature/unified-platform`

GitHub: `lessbuild/app`

Production release: `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/dbb450254562c104fcd7d5b9b999e208153258cf`

Active pointer: `/var/www/buildpusher-unified/current`

The Buildpusher pricing page now has Signal tab components for Deployer, Monitor, and Analytics. Each app keeps its own plan panel and existing plan details. Pricing cards use consistent panel sizing and shared Signal panels for limits.

Core help now passes its product documents as a collection, and the Analytics API page skips OpenAPI path-level parameters and safely renders operations with optional summary fields. The workspace switcher no longer places a Blade control directive inside a component's opening attributes, fixing the authenticated layout compile error. The status page uses a distinct component-loop variable. Four other malformed conditional component attributes in Deployer and Monitor were converted to bound `open` properties. The workspace dashboard's nested inline `@php(...)` expressions were changed to regular Blade PHP blocks so the dashboard compiles cleanly.

Core project access can be granted or revoked by workspace owners and admins from the project team panel. The operations validate the active workspace membership, protect workspace owners, record workspace audit events, and only change canonical Core project membership. They do not change product grants, product-local membership, subscriptions, or product databases.

Deployment preserved the existing shared `.env`, storage, and four product databases. No migration, database reset, or customer-data operation was run. Locked Composer dependencies and the latest built assets were installed in the mounted-volume release; production configuration, routes, and Blade caches completed; PHP-FPM reloaded successfully. Caddy configuration was unchanged.

Loopback HTTPS checks returned 200 for `/pricing`, `auth.buildpusher.com/login`, `/help`, `/help/analytics/api`, `/status`, and the Buildpusher homepage. The rendered pricing response contained the tablist, all three product tabs, and all three associated panels. Route cache inspection showed the new project-membership POST and DELETE routes on `buildpusher.com`.

Static validation passed: all 506 compiled Blade views passed `php -l`; changed PHP files passed syntax checks and Pint; `git diff --check` passed; the production dependency install and Laravel config, route, and view caches completed. The automated feature tests were authored but remain unrun under the plan-wide test deferral.

The production login page and authenticated templates were checked without signing in as a customer. An authenticated sign-in round trip still needs a controlled browser session. The root filesystem remains at 100% usage with roughly 64 MB available; release files and Composer cache were kept on the attached volume.

## Pricing card follow-up

Application commit `cdd92c690535ad66acbeda0a8d22df58304ddc79` was pushed and deployed from the same host. Inspecting the rendered cards after the initial release showed that a second, conditional class attribute replaced the static spacing and layout classes. The Deployer and Monitor cards now use a single class attribute containing both the layout classes and conditional featured styling.

The current pointer resolves to `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/cdd92c690535ad66acbeda0a8d22df58304ddc79`. Optimized production autoload generation, package discovery, route/view caches, and syntax checks of all 506 compiled Blade files passed. PHP-FPM reloaded successfully. Live loopback HTTPS checks returned 200 for pricing, login, help, Analytics API docs, and status. The rendered pricing page contains all three product tabs/panels, and every Deployer/Monitor plan retains its padding and flex layout; only the featured Pro plans carry the ring classes. A DOM-based regression was authored and remains unrun. No database migration or reset was performed.

Follow-up live checks also returned 200 for the Deployer guide and the Deployer/Monitor API references. The recent application errors inspected after deployment were background deployment SSH timeouts, separate from the repaired public page render paths.

The Auth root redirects to login (302), and the password-reset request page returns 200. Public registration returns 404 under the existing production setting `lessbuild.registration.enabled=false`; that setting was not changed. A complete authenticated login round trip remains unverified.

## Formatted-price JavaScript follow-up

Read-only inspection in Chromium found a remaining `Unexpected token ','` error: the Unlimited annual price rendered as an unquoted `1,990` in the Alpine expression. Prices are now encoded as JavaScript strings with Laravel's `Js::from`, preserving the formatted amount while allowing interval switching.

The fix was pushed as `cda5d3f` on `feature/unified-platform` and cherry-picked onto the existing production code as `49371e115726d42c829cd242eca9ffa6b1da5e09` on `fix/pricing-interval`. The production pointer now resolves to `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/49371e115726d42c829cd242eca9ffa6b1da5e09`. The unrelated in-progress feature-branch changes were not part of this hotfix. Existing locked dependencies and built assets were retained; optimized autoload, configuration, route and view caches were regenerated. All 506 compiled Blade files passed syntax checks before the atomic switch, and PHP-FPM reloaded successfully. No migration or data reset was performed.

Live Chromium inspection after release confirmed `$1,990` yearly → `$199` monthly → `$1,990` yearly, exactly one visible panel for each product tab, no JavaScript page errors, and document width 390px at a 390px mobile viewport. Public HTTPS requests returned 200 for pricing, Auth login, password reset, the help index, Deployer guide, and all three product API references. Legacy Deployer `/docs` and `/api-docs` redirects also reached the corresponding Core pages successfully. Authentication with a real account remains unverified.

Two browser regressions were authored in `tests/Browser/core-pricing.spec.js` for the four-digit price and keyboard/mobile tab behavior. The regression suite remains unrun; only static checks and the read-only live inspection above were performed.
