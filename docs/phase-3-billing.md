# Phase 3: billing

Design notes for Phase 3 of [the plan](platform-v2-plan.md).

## Model

- **Catalogue as code.** Each `PlatformService` declares its **tiers** (name, monthly price, features, limits), **add-ons** (extra quantity of a limit) and **meters** (usage with a monthly allowance per tier). Limits use entitlement keys such as `monitoring.events.monthly` or `deploy.websites.max`; `null` means unlimited.
- **Prices.** A tier's monthly amount is in the catalogue. Its Stripe price ID comes from config, per environment: `STRIPE_PRICE_<SERVICE>_<TIER>` (e.g. `STRIPE_PRICE_DEPLOY_PRO`) and `STRIPE_PRICE_<SERVICE>_ADDON_<KEY>`. A paid tier without a price ID (or without an amount) is shown but can't be bought yet.
- **One Stripe subscription per account.** It has one item per chosen paid tier and one per add-on. Changing a service updates only its own item, with proration. Free tiers have no item.
- **Local selections are the source of truth for entitlements** (`billing_selections`). Stripe is kept in step through the `PaymentProvider` contract (Stripe implementation in `app/Services/Billing`; tests use a fake). Webhooks update the subscription status and period.
- **Entitlements.** `Entitlements::for($account)->allows('monitoring.checks.max', $count)` returns a `Decision` (allowed, limit, reason). Every write that consumes a limit checks it, and the billing page shows the same numbers.
- **Usage.** Services record usage (`RecordUsage`) into hourly buckets (`usage_records`). An hourly job reports new usage to Stripe meters when a meter has a Stripe meter event name. The billing page shows this month's usage against the allowance.
- **First paid purchase** goes through Stripe Checkout (subscription mode) so Stripe collects the card and handles SCA. After that, changes go straight to the subscription. Payment methods and invoices use the Stripe customer portal and invoice list.
- **Cancel / resume per service.** Downgrading a service to Free removes its item at the end of the period (`cancel_at_period_end` on our selection); resuming before then keeps it.

## Prices (from the current apps; same price at cutover)

- **Deploy**: Free $0, Starter $9, Pro $19, Team $49, Business $99, Unlimited $199 (Deployer's plans and limits).
- **Monitoring**: Free $0, Pro $29, Team $99, Scale $299 (Monitor's plans; events per month and retention).
- **Analytics** (decided 2026-09-28; the owner left pricing to us):
  - Free $0: 3 sites, 10K pageviews a month, 90 days of event history.
  - Pro $9: unlimited sites, 100K pageviews a month, a year of event history.
  - Business $29: unlimited sites, 1M pageviews a month, two years of event history.
  - Pageviews are counted by the `analytics.pageviews` meter and shown against the allowance, with the usual usage alerts. Going over isn't charged per pageview and doesn't stop collection; it's a prompt to move up.
  - Reports, goals and exports are on every tier: tiers differ only in volume, sites and history.
- **Infrastructure** stays included: its server and website limits come from the Deploy plan, so there's no separate charge to pay twice.
- **Yearly prices** aren't offered at launch. One subscription can only have one interval, and monthly keeps plan changes and proration simple. If they come back, they'd be ten months' price for a year.

## Needed at launch

- Stripe price IDs for every paid tier and add-on in each environment (`.env`, `STRIPE_PRICE_<SERVICE>_<TIER>`): Deploy Starter, Pro, Team, Business and Unlimited; Monitoring Pro, Team and Scale; Analytics Pro and Business. A paid tier without one is shown but can't be bought.
