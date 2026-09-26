# Core workspace creation authority

## 25 September 2026

Verified users can create a canonical Core workspace from the Signal workspace directory. Core creates the workspace and its owner membership in one Core transaction; a new workspace receives no implicit product grant or subscription. The desktop and mobile workspace switchers link to the shared Core workspace directory.

When Analytics uses Core authentication authority, its workspace page directs users to the Core directory. A submit to the legacy Analytics workspace endpoint is redirected through the configured Core route link, and an account with a reconciled Analytics identity but no Core workspace grant receives a conflict response instead of an unlinked product-local workspace. The legacy-auth flow remains available during transition.

Feature tests cover verified workspace creation, denial for an unverified account, and Analytics refusing to auto-create a product-local workspace under Core authority. They have not been run, per the instruction to defer test execution until the full plan is complete. PHP syntax checks, Pint, Blade compilation, Core route registration, and `git diff --check` passed. No migration, production database write, or release is part of this change.
