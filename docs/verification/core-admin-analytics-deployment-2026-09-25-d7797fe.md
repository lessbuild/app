# Core platform analytics release

Application commit: `d7797fed8fd532ef161977e0a30ca2af44a179e8`

Branch: `feature/unified-platform`

GitHub: `lessbuild/app`

Production release: `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/d7797fed8fd532ef161977e0a30ca2af44a179e8`

Core now serves the platform-wide Business Analytics page at `buildpusher.com/admin/deployer/analytics`. It uses the shared Core admin Signal shell and is linked alongside Deployer access-request administration. The route requires Core authentication, an exact mapped Deployer principal, and the existing Deployer platform-admin allowlist. Analytics aggregation stays in the existing service, including its private, no-store response headers. The old `deployer.buildpusher.com/admin/analytics` route remains available.

The GitHub App private-key setup page remains local/testing-only and returns 404 in production by design. Its existing admin route and restrictions are unchanged.

The release was built and activated on this machine using the local release directories and an atomic `current` symlink switch; no SSH was used. Existing Composer dependencies, built assets, shared environment configuration, and persistent storage were reused. Package discovery and production configuration, route, and Blade caches completed successfully. Route inspection confirmed both Core admin paths use platform authentication and `ResolveProductPrincipal:deployer`; no database migration or data operation was needed.

Static checks passed: PHP syntax, Pint, Blade compilation, route listing, and `git diff --check`. Loopback HTTPS GET checks returned 200 for the homepage and central login. Both Core admin routes redirected unauthenticated requests to central login, and the legacy Deployer analytics route still redirected with its return target. No privileged admin page was opened and no data was changed during the smoke check.

Core route coverage was added to `tests/Feature/Core/CoreAdminAccessRequestTest.php`, including the analytics no-cache contract. Automated tests remain unrun under the plan-wide test deferral. Authenticated admin acceptance, full administrator authorization review, the local-only GitHub setup exception, and the remaining D23/D24 gates stay open.
