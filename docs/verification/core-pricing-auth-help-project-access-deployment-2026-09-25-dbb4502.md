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
