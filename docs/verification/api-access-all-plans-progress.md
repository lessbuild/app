# API access on every plan — verification record

Date: 2026-09-14  
Integration target: `main`  
Feature branch: `feat/api-access-all-plans-20260914`

## Problem

The existing control-plane API and scoped personal access-token workflow used
the `api` billing entitlement. Free, Starter, Pro and Team did not include
that entitlement, so API automation was unavailable below Business even
though the token, authorization and API routes already supported those plans.

## Responsibility boundary

This slice keeps the existing boundaries intact:

- `Entitlements` decides whether a plan includes the API capability.
- `ControlPlaneAccess` continues to enforce API entitlement, workspace network
  policy and the token's `read`, `deploy` or `manage` ability.
- `RouteServiceProvider` owns request-volume throttling and reads the plan's
  configured quota.
- Existing token actions, requests, policies, controllers and API response
  resources remain responsible for their current operations.

No generic repository, interface, action or service was introduced. This is a
configuration plus rate-limiting change applying single responsibility and
dependency inversion through the existing collaborators.

## Behavior

Every configured plan includes API access. Limits are applied per minute and
per current workspace:

| Plan | Requests per minute |
| --- | ---: |
| Free | 60 |
| Starter | 120 |
| Pro | 300 |
| Team | 600 |
| Business | 1,200 |
| Unlimited | 3,000 |

The workspace owner's subscribed plan supplies the quota for workspace
members. Requests without an authenticated workspace use the existing Free/IP
fallback. A throttled request returns the existing HTTP 429 response with
standard rate-limit headers.

The change preserves:

- Sanctum token abilities, ownership and expiry;
- organization scoping and network allowlists;
- operation-specific entitlements such as scaling and backups;
- validation and authorization ordering;
- API routes, envelopes, status codes and OpenAPI output; and
- token secret handling and existing queued-operation behavior.

## Entry points changed

- `config/billing.php`
- `app/Providers/RouteServiceProvider.php`
- `resources/views/scenes/pricing.blade.php`
- `resources/views/scenes/billing/index.blade.php`
- `tests/Feature/ApiPlanAccessTest.php`
- Existing automation, entitlement, billing and analytics tests were updated
  to characterize the new API entitlement without weakening unrelated paid
  feature denials.

## Verification

Focused command:

```sh
/root/.local/share/buildpusher/php-8.5.10/bin/php artisan test --compact \
  tests/Feature/AdminAnalyticsTest.php \
  tests/Feature/ApiPlanAccessTest.php \
  tests/Feature/AutomationTest.php \
  tests/Feature/EntitlementTest.php \
  tests/Feature/BillingTest.php
```

Result: **58 tests passed / 273 assertions**.

Complete strict command:

```sh
/root/.local/share/buildpusher/php-8.5.10/bin/php artisan test --compact \
  --fail-on-warning --fail-on-risky --fail-on-deprecation \
  --fail-on-phpunit-deprecation --do-not-record-test-run-history
```

Result: **1,525 tests passed / 12,860 assertions** in 541.67 seconds.

Also passed:

- required-PHP Pint test;
- `composer validate --no-check-publish`;
- `composer check-platform-reqs`; and
- `git diff --check`.

Dependency lockfiles were unchanged. The disposable dev acceptance checkout,
production credentials, provider accounts and live acceptance drill were not
used.

## Commits and next task

- `139fc1f feat: open API access across all plans` — pushed to
  `origin/feat/api-access-all-plans-20260914`.
- `6749fed test: keep analytics denial fixture paid` — pushed to
  `origin/feat/api-access-all-plans-20260914`.

Verification/documentation commit `c1122c3` was pushed on the feature branch.
The branch is ready to be fast-forwarded into `main`; after integration, use
`main` for subsequent work and start with a new scoped inventory.
