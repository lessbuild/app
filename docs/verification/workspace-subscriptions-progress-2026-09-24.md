# Workspace subscriptions progress

## 24 September 2026 — add the dedicated workspace Plans page (verification deferred)

Core now exposes `/workspaces/{workspace}/subscriptions` in its Signal workspace shell. It resolves Deployer, Monitor, and Analytics independently from the Core current-subscription records and immutable plan snapshots. Workspace owners and billing managers see each current plan, subscription state, billing period, and active app-access seat count. Other members see only their own access state for each product; the page does not load subscription details or workspace-wide seat counts for them. The page never renders provider subscription identifiers.

Deployer and Monitor billing buttons link only to their existing named billing routes and only when the configured product origin agrees with the route origin. Cross-host navigation uses the existing Core SSO issue route. Analytics has no paid catalog in the source application, so the page explains that existing imported workspaces retain a separate no-charge legacy access slot without implying that every new workspace already has one.

Six feature regressions are authored: three cover separate product plan/status/period/seat summaries and privacy for billing managers, regular members, and non-members; three cover same-origin Deployer and Monitor billing navigation, rejection of configured origins that disagree with either named billing route, and the lack of an Analytics billing destination before Analytics has its own billing route. They have not been run, following the instruction to defer test execution until the plan is complete.

Static verification: Pint formatted the changed PHP files; `php -l` passed for the controller, billing-link service, route file, and feature-test file; Blade view caching completed; `git diff --check` passed. No test runner was invoked for this slice.

Core-mode Deployer workspace checkout, customer portal, webhook projection, and scheduled reconciliation have since been implemented; see [Deployer workspace billing progress](deployer-workspace-billing-progress-2026-09-25.md). They remain behind the legacy billing-authority default and have not passed deferred tests or Stripe integration acceptance. Analytics paid-plan design and complete subscription lifecycle acceptance remain open.

## 25 September 2026 — Core billing access handoff

Core now includes the mapped Deployer organization or Monitor workspace ID in its billing handoff. Under Core authentication, Deployer and Monitor allow the mapped product billing routes only to a Core workspace owner or billing manager, even when that person has no app usage grant or product-local workspace membership. The ordinary product routes continue to require their active product grant. Monitor resolves billing context in a billing-specific session key and rechecks Core billing permission on billing actions; it does not change the user's active Monitor workspace. Product billing links reject missing, non-numeric, or mismatched workspace targets. Regression coverage for billing managers, ordinary members, app-grant revocation, and workspace-qualified links is authored but remains unrun until the full plan is complete.

The Monitor plan catalog and webhook projection can use Core workspace subscriptions when `MONITOR_PLAN_AUTHORITY=core`. Deployer still uses its legacy user-owned Cashier subscription flow; it must be replaced or adapted to create workspace-scoped Core subscriptions before the Deployer handoff can be treated as a complete billing lifecycle. Do not interpret the access handoff alone as payment or webhook completion.

## 25 September 2026 — preview app-seat impact before changing access

The workspace team page now shows the seat impact beside each product access control. A new grant previews the product's projected active-seat count under that product's own plan; unlimited plans say the grant adds an active app seat, and changing an existing role explains that usage stays flat while choosing “No access” frees that app seat. A full seat pool and a missing plan keep their existing blocked states. The write path still validates the plan and seat limit under its existing workspace lock, so the displayed preview does not replace enforcement.

The preview reports Core app-access seats. It does not label a provider subscription's aggregate item quantity as seats. Deployer now separately normalizes its additional-seat subscription line items; Monitor remains represented by active access against its plan's included seat limit, and Analytics has no paid seat catalog. Tests covering the new-grant preview and active-role/revocation explanation are authored but intentionally unrun until the unified-application plan is complete. Static PHP/Blade checks are recorded with the corresponding code change.

## 25 September 2026 — normalize Deployer subscription seat add-ons

Deployer now converts configured Stripe base and seat-addon price items into an explicit additional-seat count in Core subscription metadata. The value is populated by both the preserved Cashier importer and verified subscription webhooks. Core keeps Deployer-specific line-item rules in the Deployer module; the Plans page displays the count only to workspace owners and billing managers. Unknown prices, missing quantities, mixed billing intervals, or missing base items are shown as unverified instead of being treated as zero. The existing aggregate provider `quantity` remains intact for migration history and is not used as a seat count.

Deferred feature coverage checks imported and webhook-projected add-ons, rejects unrecognized line items, displays the normalized quantity to billing managers, and hides it from ordinary members. No tests have been run. Analytics pricing and billing terms, Monitor's included-seat interpretation, Deployer's unresolved shared-owner subscription, and complete billing lifecycle/seat-metering acceptance remain open.

Static verification passed for the changed PHP files (`php -l` and Pint), Blade view compilation, and `git diff --check`. The test runner remains uninvoked.
