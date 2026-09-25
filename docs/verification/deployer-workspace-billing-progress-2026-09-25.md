# Deployer workspace billing progress

## Core-owned Deployer checkout and lifecycle

Deployer now has a Core-backed billing path behind `DEPLOYER_PLAN_AUTHORITY=core`. In that mode the billing screen reads the mapped workspace's Core subscription and plan snapshot, and checkout, the customer portal, scheduled cancellation, and resume operate on that workspace's `deployer` subscription and `deployer` Stripe account key. The legacy Cashier user-owned path remains available while production billing data and cutover are rehearsed; the example configuration still defaults to `legacy`.

Checkout keeps Deployer's configured monthly/yearly prices, included/extra seat rules, promotion-code support, and 14-day first-subscription trial behavior. Its Stripe metadata carries both the Core workspace ID and mapped Deployer organization ID. Pending checkout state is workspace-scoped and idempotent, and the hosted session is explicitly short-lived so another plan cannot open a competing checkout for the same workspace.

Cashier continues to verify the existing Deployer Stripe webhook signature and dispatch its `WebhookReceived` event. When Core plan authority is enabled, the Deployer listener projects supported checkout, subscription, and invoice events into `product_subscriptions`, `current_product_subscriptions`, `billing_customers`, and `product_billing_events`. The projection is idempotent and ordered, keeps unknown plans or conflicting workspace/customer/subscription mappings out of entitlements, preserves paid history on cancellation, and restores the workspace's free plan when the paid subscription ends. Pending events can be retrieved from Stripe and reconciled by `deployer:billing:reconcile-core-events`; Core mode schedules that bounded retry every 15 minutes.

Legacy Cashier period-end cancellations are imported as Core `active` subscriptions with `cancel_at` and `current_period_ends_at`, preserving the paid grace period. At expiry the normal Core plan resolver rejects the elapsed term even if the terminal webhook has not arrived yet.

## Deferred regressions and checks

New tests cover workspace-scoped Stripe line items, trial/customer metadata, seat quantities, product-slot isolation, duplicate delivery, subscription cancellation, conflicting mappings, pending-event replay, webhook listener dispatch, and Deployer Cashier grace-period migration. They are authored but intentionally unrun until the full unified-application plan is complete.

Static checks passed for all touched PHP files: `php -l`, Pint, `git diff --check`, Blade view compilation, route listing, and console command registration. No PHPUnit, browser, or other test runner was invoked. No live Stripe API request or production configuration change was made.

## Remaining billing acceptance

Do not treat the Deployer billing cutover as complete yet. `DEPLOYER_PLAN_AUTHORITY` remains on its legacy default; Core checkout and webhook behavior has not been exercised against Stripe test mode or the full integration suite. Reconcile Deployer's shared-owner subscription review records without assigning one old subscription to multiple workspaces, verify portal configuration and webhook endpoint delivery, run independent-product billing acceptance after the plan is complete, then enable Core authority and verify workspace isolation, trials, renewals, seats, dunning, grace periods, cancellation, and recovery before release.
