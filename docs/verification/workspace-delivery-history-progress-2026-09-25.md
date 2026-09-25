# Workspace delivery history progress — 25 September 2026

Core now provides a Signal-themed workspace delivery-history page for Deployer repository webhook events and Monitor signed alert notifications. The page filters by product and delivery status, paginates the combined result, and shows up to 100 recent records from each available product. Source providers first require the active workspace grant, project membership, explicit active resource mapping, and matching source workspace/organization before returning a record.

Core receives only a small typed summary: product, project, event label, status, attempt count where available, timestamp, and a link to the source product. It does not receive webhook bodies, endpoint URLs, signing secrets, error bodies, or provider responses. Details and retries remain in the source application, where existing product policies reauthorize the request. Deployer repository events do not expose a replay action because the source event belongs to the Git provider. Analytics' current collection API emits no outbound webhook deliveries.

The new regression coverage checks the Core aggregation/filter/redaction boundary and Monitor mapping, webhook-only destination selection, status/attempt summaries, and workspace isolation. Tests are authored and intentionally unrun until the unified plan is complete. PHP syntax and Pint checks passed, Blade cache compilation succeeded, and `core.workspace.deliveries` is registered.

I13 remains open for a shared credential inventory and actions, additional product delivery coverage if those products add webhook providers, complete endpoint/payload compatibility fixtures, and representative public-client rehearsal.
