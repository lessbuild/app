# Monitor Core billing projection progress

## 25 September 2026

Monitor's billing page can use Core as its plan authority while preserving Monitor's Stripe account and separate subscription slot. In Core mode, checkout requires a reconciled workspace mapping, active subscriptions are directed to Stripe's portal, and the verified webhook processor projects Monitor events without changing Deployer or Analytics subscriptions. Legacy plan authority remains the default until cutover configuration is explicitly enabled.

The Core projection is idempotent by Stripe account and event ID. It preserves unresolved workspace events as pending, unknown plans and conflicting subscriptions for review, and paid subscription history after cancellation. A new `monitor:billing:reconcile-core-events` command retrieves pending event IDs from Stripe rather than persisting full webhook payloads in Core. Core-authority installations schedule a bounded batch every fifteen minutes, capped at five automatic attempts per pending event. Operators can retry one event by ID after reconciliation, or include held-for-review events after correcting their underlying configuration.

Historic Monitor billing customer/subscription IDs and event history were not imported during the preserved-data pass, so existing provider ownership still needs reconciliation. No production Stripe calls, release, or cutover were performed for this change. Stripe checkout, portal, webhook ordering, automatic/manual reconciliation, and cross-product isolation all require acceptance before enabling Core billing authority.

Regression coverage was authored in `tests/Feature/Monitor/SyncMonitorBillingEventIntoCoreTest.php` for replaying an event after its workspace mapping is reconciled. **No test suite was run**, following the instruction to defer test execution until the plan is complete. Static verification passed: PHP syntax checks, Pint's formatting check, and `git diff --check`.
