# Analytics plan-enforced site management

## 25 September 2026

Analytics site creation now resolves the workspace's independent Core Analytics plan before writing. The plan must be available, include `site_management`, and contain an explicit `sites` limit. Finite limits are checked inside an Analytics database transaction after locking the product workspace row, so concurrent site-creation requests serialize against the same quota. An explicit `null` site limit means unlimited; a missing limit fails closed.

The add-site screen shows current site usage, the plan allowance, and why creation is unavailable. Site managers see the entry point; viewers cannot open it. The final write action repeats authorization/quota checks regardless of what the page displayed. Existing imported `legacy_access` snapshots explicitly grant existing capabilities with an unlimited site allowance, so this change does not narrow preserved access.

Regression cases were authored for finite-limit denial, unlimited legacy creation, and viewer access. They have not been run, per the instruction to defer test execution until the plan is complete. This does not resolve Analytics pricing, monthly event metering/period semantics, or paid checkout, portal, and webhook lifecycle; those remain open pending product terms. No migration or production data change was made.
