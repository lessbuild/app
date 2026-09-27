# Phase 4: Monitoring

Design notes for the Monitoring service in Phase 4 of [the plan](platform-v2-plan.md). It is ported from the standalone Monitor app in five parts:

1. **Monitors, incidents, alerts and maintenance.**
2. **Telemetry**: ingest (`/api/v1/ingest`, OTLP, deployments), events, issues, traces, releases and the service map.
3. Alert rules on telemetry, and escalations.
4. Status pages (`/status/{slug}`).
5. Metrics, dashboards, SLOs and usage metering.

## Model

- **Monitors** belong to one of a project's environments. There are six types: HTTP, DNS, TLS, TCP, heartbeat (cron jobs report in) and queue (a collector reports queue counts and each worker reports its own heartbeat). URLs, tokens, hostnames and expected DNS records are stored encrypted and never appear in history, alerts or the audit log.
- **Checks** for HTTP, DNS, TLS and TCP run on the `checks` database queue. `monitors:check` runs every minute: it recovers interrupted checks, queues due ones and evaluates heartbeat and queue deadlines. A check row and its job are written in one transaction; the job's payload UUID (`jobs.job_uuid`, a generated column) lets a changed or paused monitor discard its queued job.
- **Incidents** open after a monitor fails enough checks in a row and close when it passes enough in a row. Changing what a monitor checks closes its incident as “monitor changed”, never as recovered. An incident belongs to the account and the project, so the project pages list it directly.
- **Alert destinations** belong to the account and are shared by every project's monitors: a member's email, a signed webhook, Slack, Microsoft Teams, Discord or PagerDuty. Each monitor picks up to five. Deliveries are an outbox (`alert_deliveries`) sent from the `alerts` queue, with retries and backoff; `alerts:recover` requeues deliveries whose jobs went missing.
- **Maintenance windows** belong to the account. While one is active no monitor in the account opens an incident; checks keep running and are recorded.
- **Turning Monitoring off** for a project keeps its monitors but stops running them, and heartbeat and queue signals are refused.

## Telemetry (part 2)

- **Ingest keys** belong to an environment (`bcn_…`, only the hash is kept). Apps send JSON batches to `/api/v1/ingest` or OTLP/HTTP JSON to `/api/v1/otlp/v1/{traces,logs,metrics}`; pipelines record deployments at `/api/v1/deployments`. A project with Monitoring off refuses new data.
- **Receipts** make ingest idempotent: a retried batch returns the original receipt and isn't counted twice. Accepted batches are kept encrypted (`ingest_payloads`) and processed on the `telemetry` database queue; `telemetry:recover` requeues ones whose job went missing, and failed ones can be retried from an environment's Deliveries page.
- **Processing** writes events, groups exceptions into **issues** (by fingerprint, per project), links **releases** (`service.version`) and records metric samples.
- **Usage**: every processed delivery adds a row to `telemetry_usage_entries` (the ledger behind the monthly allowance, `monitoring.events.monthly`) and to the `monitoring.events` billing meter. A delivery over the allowance fails with “plan limit reached” and can be retried after upgrading.
- **Retention**: `telemetry:prune` deletes events and finished receipts older than the tier's `monitoring.retention.days`.
- **Incidents** show deployments to the same environment shortly before they opened (`monitoring.deployment_context.minutes`: 0 on Free, 60/120/240 on paid tiers).
- Old Monitor's “legacy replay” (matching retries against events stored before receipts existed) isn't ported: the importer copies event identities, so retries after cutover still deduplicate.

## Access

- Anyone who can use Monitoring on the project sees monitors and incidents.
- Managing monitors and responding to incidents (acknowledge, assign, note) needs `manageService` for Monitoring on the project (owners, admins and members with access to Monitoring).
- Alert destinations and maintenance windows affect the whole account, so they need account settings access (owners and admins).
- Incidents and issues can be assigned to owners, admins and members who may use Monitoring. Someone who leaves the account, or loses that access, is unassigned from both.
- Monitor, alert destination and ingest key changes are recorded in the audit log. Creating ingest keys, recording deployments and changing issues needs `manageService` for Monitoring.

## Public contracts kept from the Monitor app

- `POST /api/v1/heartbeats/{monitor}` with the monitor's heartbeat key as a bearer token, and `POST /api/v1/queues/{monitor}/snapshots` and `/workers` with the queue key. The same bodies, limits, status codes and `{"data": …}` receipts.
- Heartbeat keys start `bch_` and queue keys `bqk_`.
- Webhook deliveries keep the `X-Beacon-Delivery`, `X-Beacon-Timestamp` and `X-Beacon-Signature` headers and the payload shape; the payload has a new `project` key alongside `application`.
- Ingest keys (`bcn_…`) keep working after import: they're stored as the same SHA-256 hash. `/api/v1/ingest`, OTLP, `/api/v1/ingest/receipts/{id}` and `/api/v1/deployments` keep their bodies, headers (`X-Beacon-*`), status codes and limits. One change: `environment_id` in deployment responses is now the environment's ULID.
- **For the importer (Phase 7):** the heartbeat and queue URLs contain the monitor's ID, so imported monitors must keep their old IDs (`legacy_id` records the mapping for everything else). Deployment fingerprints are an HMAC with the app key, so the old app's key goes into `APP_PREVIOUS_KEYS` for retried deployments to match.

## Configuration

- `config/monitoring.php`: the location label shown on checks, the alert mailer (`ALERT_MAILER`), ingest limits, the telemetry queue connection (`TELEMETRY_QUEUE_CONNECTION`) and the redaction rules.
- `config/queue.php`: the `telemetry`, `checks` and `alerts` database connections. Run a worker for each in production (`composer dev` starts them locally).
- `config/mail.php`: the `alert_smtp` mailer (SMTP with a 10-second timeout). Alert emails are only sent through an SMTP mailer with a timeout of 15 seconds or less.
