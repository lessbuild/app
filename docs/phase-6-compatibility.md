# Phase 6: Public compatibility

Phase 6 of [the plan](platform-v2-plan.md): every public contract of the three old applications keeps working unchanged, and the platform gets its public pages. Most contracts were ported with their services in Phase 4; this phase checks them all in one place and fills the gaps.

The parts:

1. **Contract suite, old IDs and the platform status page.**
2. **OpenAPI:** a machine-readable description of the API, and a reference page.
3. **Public site:** home, one page per service, and pricing (D24).

## Contracts and status (part 1)

- `tests/Feature/Compatibility/PublicContractsTest.php` is the list of public contracts. For each it checks the path, method and name still resolve, and pins the unauthenticated or invalid answer (status code and JSON `status`), so a change shows up as a failing test. The contracts:
  - **Analytics:** `/tracker/v1.js`, `POST|OPTIONS /api/v1/collect/{publicId}`.
  - **Monitoring:**
    - `POST /api/v1/heartbeats/{id}` and `/api/v1/queues/{id}/snapshots|workers`;
    - `POST /api/v1/ingest`, `GET /api/v1/ingest/receipts/{id}` and `POST /api/v1/otlp/v1/{traces|logs|metrics}`;
    - `POST /api/v1/deployments`.
  - **Deploy:**
    - `POST /api/repositories/{id}/webhook` and `POST /api/github-app/webhook`;
    - `/github-app/callback`;
    - the signed `/builds/{id}/deployment/callback/{event}`, `/servers/{id}/provisioning/callback/{event}` and `/websites/{id}/provisioning/callback/{event}`;
    - Deployer API v1 under `/api/v1`.
  - **Billing:** `POST /webhooks/stripe`.
  - **Status pages:** `/status/{slug}`, `/status/{slug}/report.json`, subscriptions and their confirm/unsubscribe links, and the `/status/{deployer|monitor}/{slug}` redirects.
- **Old numeric IDs in Deployer API v1:** projects and environments get a `legacy_id` (the importer fills it in Phase 7). A path with a number where a ULID goes (`/api/v1/projects/42`, `/api/v1/environments/7/deploy`) finds the record by `legacy_id`, within the token's account, so existing scripts keep working. Responses carry the v2 ID.
- **Platform status** (Core's `/status` and `/status/report.json`, same JSON):
  - The JSON is `status` (operational or degraded), `operational`, `checked_at`, and `components` (name, description, status, operational).
  - The components are coarse, with nothing internal: the application and database, background processing (queues within their limits and the scheduler's heartbeat), and each service.
  - Answers are cached for 30 seconds (`Cache-Control: public, max-age=30`).
  - An operator who runs their own Monitoring status page for the platform can point `/status` at it with `PLATFORM_STATUS_PAGE={slug}`.

## OpenAPI (part 2)

- `resources/openapi/v1.yaml` describes the public API: account and API tokens, Deployer API v1, ingest, heartbeats, queues and collect. It covers paths, parameters, request bodies, response codes, the `bearer` and ingest-key security schemes, and the token scopes per operation.
- It's served as JSON at `GET /api/openapi.json`, and read at `/docs/api`.
- A test compares it with the routes: every `api/*` route must be in the spec, and every path in the spec must be a route. Adding an endpoint without documenting it fails CI.

## Public site (part 3)

- Guests get a home page at `/` (signed-in people still go to their dashboard). It covers the four services and how they fit together, adapted from the old marketing configuration (`app/config/marketing.php`).
- `/services/{deploy|infrastructure|monitoring|analytics}`: each service's capabilities, workflows and guardrails.
- `/pricing` is built from the billing catalogue, so it always matches what can be bought: each service's tiers, limits, add-ons and meters. Prices are the catalogue's (the owner's pricing decisions change the catalogue, not the page).
- Public pages use the Signal components, are cached publicly for five minutes, and are indexed (`robots` allows them; the app stays `noindex`).
