# Phase 4: Monitoring

Design notes for the Monitoring service in Phase 4 of [the plan](platform-v2-plan.md). It is ported from the standalone Monitor app in five parts:

1. **Monitors, incidents, alerts and maintenance** (this part).
2. Telemetry ingest (`/api/v1/ingest`, OTLP, deployments), events, issues, traces and releases.
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

## Access

- Anyone who can use Monitoring on the project sees monitors and incidents.
- Managing monitors and responding to incidents (acknowledge, assign, note) needs `manageService` for Monitoring on the project (owners, admins and members with access to Monitoring).
- Alert destinations and maintenance windows affect the whole account, so they need account settings access (owners and admins).
- Incidents can be assigned to owners, admins and members who may use Monitoring. Someone who leaves the account, or loses that access, is unassigned.
- Monitor and destination changes are recorded in the audit log.

## Public contracts kept from the Monitor app

- `POST /api/v1/heartbeats/{monitor}` with the monitor's heartbeat key as a bearer token, and `POST /api/v1/queues/{monitor}/snapshots` and `/workers` with the queue key. The same bodies, limits, status codes and `{"data": …}` receipts.
- Heartbeat keys start `bch_` and queue keys `bqk_`.
- Webhook deliveries keep the `X-Beacon-Delivery`, `X-Beacon-Timestamp` and `X-Beacon-Signature` headers and the payload shape; the payload has a new `project` key alongside `application`.
- **For the importer (Phase 7):** these URLs contain the monitor's ID, so imported monitors must keep their old IDs (`legacy_id` records the mapping for everything else).

## Configuration

- `config/monitoring.php`: the location label shown on checks, the alert mailer (`ALERT_MAILER`) and the redaction rules for labels.
- `config/queue.php`: the `checks` and `alerts` database connections. Run workers for both queues in production.
- `config/mail.php`: the `alert_smtp` mailer (SMTP with a 10-second timeout). Alert emails are only sent through an SMTP mailer with a timeout of 15 seconds or less.
