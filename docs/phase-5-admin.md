# Phase 5: Admin panel and platform operations

Design notes for Phase 5 of [the plan](platform-v2-plan.md): the platform operators' admin panel (D20 health, D25/M20/A09 operations, Deployer's business analytics and access requests), built on the platform-admin access design started on the old branch (`61b11ae`).

The parts:

1. **Access and shell:** the platform-admin flag, `platform:admin`, the `/admin` gate, and an audit trail of admin actions.
2. **Health and queues:** system checks, queue backlogs and failed jobs, the scheduler heartbeat, and a JSON report.
3. **Business analytics and customer lookup.**
4. **Access requests:** registration can be closed, with requests and invitations.
5. **Feature flags and retention.**

## Access (part 1)

- `users.is_platform_admin` and `platform_admin_granted_at`. Every grant and revoke is kept in `platform_admin_events`: who, by whom (null from the CLI), and the source.
- `php artisan platform:admin {email} --grant|--revoke`, `--list`, and `--import-allowlist` (from `PLATFORM_ADMIN_EMAILS`). Revoking the last admin needs `--allow-last`.
  - There's no page for granting admin rights, so a compromised admin session can't create more admins.
- **`/admin` gate:**
  - Anyone who isn't a platform admin gets a 404, so the area isn't advertised.
  - Admins without an authenticator app or a passkey are sent to their security settings.
  - Everyone else confirms who they are (password, passkey or social sign-in, the usual confirmation page) at least every 15 minutes.
- **Audit:** admin actions that change something (reviewing access requests, toggling flags, retrying or forgetting failed jobs) and opening a customer's page are recorded in `platform_admin_events`, with the actor and a short description. The admin home shows the latest.
- The admin area has its own layout: health, queues, analytics, customers, access requests and flags, plus a link back to the dashboard. A platform admin sees an "Admin" link in the user menu.

## Health and queues (part 2)

- **Checks**, each with a pass/fail and a detail:
  - the application key and URL;
  - database connection and pending migrations;
  - storage and cache writable;
  - debug off and the queue asynchronous in production;
  - mail configured;
  - Stripe keys (when billing is on);
  - the scheduler's heartbeat, recorded every minute by `platform:heartbeat`, fresh within 3 minutes.
- **Queues:** pending jobs and the oldest job's age per queue (`default`, `checks`, `alerts`, `telemetry`, `terminals`, `analytics`, …), with limits from `config/platform.php`. Failed jobs are listed (queue, job, when, first line of the error). They can be retried or forgotten one at a time or all together.
- `GET /admin/health/report.json` downloads the same data, uncached.
- `GET /up` stays the load balancer's liveness check.

## Business analytics and customer lookup (part 3)

- **Analytics:**
  - totals for users, users active in the last 30 days, accounts, paid accounts and new sign-ups;
  - estimated monthly revenue from each account's chosen tiers (catalogue prices), with 30-day churn (subscriptions ended);
  - per service: accounts on each tier;
  - a 30-day daily trend of sign-ups, deploys, monitoring checks and analytics events.
  - It's computed on request and cached for five minutes.
- **Customers:** search users and accounts by email, name, ID or Stripe customer ID.
  - An account's page shows its members and roles, projects and enabled services, tiers and billing state, usage against limits, and recent audit entries.
  - A user's page shows their accounts, sign-in history, and security (2FA, passkeys; never secrets).
  - Read-only: support changes go through the customer. Impersonation isn't offered.

## Access requests (part 4)

- `REGISTRATION_OPEN` (default `true`): when it's false, the sign-up page asks for an invitation, and `/request-access` takes a request (name, email, company, team size, use case). Deployer ran closed.
- Requests are encrypted at rest and deduplicated by email hash. The requester gets a receipt email; platform admins get a notification.
- Admins mark requests contacted, invited or declined, with notes. Inviting emails a one-time registration link (7 days, `REGISTRATION_INVITATION_DAYS`). Signing up through it accepts the request.
- Requests that were declined or accepted are deleted after 180 days.

## Feature flags and retention (part 5)

- **Flags:** a flag has a key, a description, and a state: off, on for everyone, or on for chosen accounts. Code asks `Features::enabled('key', $account)`. Admins create flags and change them; each change is audited. Unknown keys are off.
- **Retention**, each scheduled daily with its window in `config/platform.php`:
  - sign-in events after 180 days;
  - read notifications after 90 days;
  - repository webhook deliveries after 30 days;
  - access requests as above;
  - platform admin events after 2 years.
  - The admin health page lists every retention job with its window and last run.

## Public contracts

- None change. The old platform status page (`/status`, `/status/report.json`) becomes a Monitoring status page the operator runs for the platform itself; that's Phase 6.
