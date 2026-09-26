# Core access-request administration release

Application commit: `4f97e476ae56013d97ae31d6c4f259ad389544d8`

Branch: `feature/unified-platform`

GitHub: `lessbuild/app`

Production release: `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/4f97e476ae56013d97ae31d6c4f259ad389544d8`

Buildpusher Core now serves the Deployer access-request review queue, CSV export, and review updates at `buildpusher.com/admin/deployer/access-requests`. The Core screen uses the shared Signal Topbar SaaS shell, and the request list and review dialog use shared Signal components. Core authentication must resolve the signed-in account to its exact Deployer principal; the existing Deployer platform-admin gate still authorizes each list, export, and update action. The original Deployer-hosted admin routes remain available.

Access-request records, encryption, reviewer IDs, invitation handling, notifications, export logic, and throttles remain owned by Deployer. Core routes call the existing controller and review action. This release has no database migrations or data movement. No review, export, invitation, or other data-changing form was submitted during the live smoke check.

The release was staged and activated on the production machine through its local release directories and an atomic `current` symlink change; SSH was not used. Existing Composer dependencies, built assets, shared environment configuration, and persistent storage were reused. Package discovery, configuration caching, route caching, and Blade view caching succeeded. Production route inspection showed all three Core endpoints on `buildpusher.com`, protected by platform authentication and `ResolveProductPrincipal:deployer`; the list and update are limited to 30 requests per minute and export to 10. No migration command was run.

Static verification passed: PHP syntax, Pint, Blade compilation, route listing, and `git diff --check`. Loopback HTTPS GET checks returned 200 for the Buildpusher homepage and all three product description pages, and for central login. The protected Core admin URL redirected an unauthenticated visitor to central login. The legacy Deployer admin URL still redirects to central login with its Deployer return target.

Core admin route regressions were added in `tests/Feature/Core/CoreAdminAccessRequestTest.php`. Automated tests remain unrun under the explicit plan-wide test deferral. Authenticated cross-host review, export, and invitation acceptance, Core admin authorization review, and the remaining D23/D24 acceptance work are still open.
