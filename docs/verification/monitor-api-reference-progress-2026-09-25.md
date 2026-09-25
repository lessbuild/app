# Monitor API reference progress — 25 September 2026

Core Help now documents Monitor's signed, outbound custom-webhook destination alongside the inbound Monitor API. When the Monitor host and route are enabled, the reference links to the existing destination manager through the shared-auth route. It also makes clear that Slack, Teams, Discord, and PagerDuty use separate provider payloads.

The receiver guidance follows `AlertNotificationTransport`, `RecordAlertDeliveries`, `AlertDeliveryQueue`, and `DeliverAlertNotification`: Monitor sends the stable delivery ID in both the `X-Beacon-Delivery` header and JSON `id`, signs `timestamp + "." + exact raw request body` with HMAC-SHA256, and expects a 2xx acknowledgement without following redirects. It describes the short receiver-side replay window, at-least-once behavior, supported event fields, five-attempt automatic cycle, retryable statuses, bounded `Retry-After`, and the preserved ID on an authorized manual retry. The destination signing key stays in Monitor and is not included in Core documentation or the workspace delivery summary.

Regression assertions cover the rendered signature fields, verification formula, and retry ceiling. They are authored and intentionally unrun until the unified plan is complete. The product route is guarded by Monitor's enabled-host configuration; a route-list check with Monitor enabled confirmed its destination-manager path. PHP syntax checks and Pint passed, and Blade cache compilation succeeded and was cleared.

I13 remains incomplete. Route/payload fixtures for every public endpoint, remaining callback-surface review, broad delivery retry coverage, and representative client rehearsal remain open.
