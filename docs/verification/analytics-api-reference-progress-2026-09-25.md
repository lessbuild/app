# Analytics API reference progress — 25 September 2026

Core Help now has a Buildpusher Analytics tracker and API page alongside the Deployer and Monitor references. When Analytics is enabled and its configured URL is a path-free origin matching the Analytics route host, the page links to a public OpenAPI 3.1 document served from `GET /api/v1/openapi.json` on the Analytics host.

The generated contract follows the actual collection request: one to twenty events, UUID event IDs, supported pageview/event types, accepted optional fields, server-receipt timestamps, and the sanitized `name` property. It records CORS origin validation, public site IDs, `202` asynchronous acceptance, duplicate retry behavior, rate limits, plan unavailability, and the browser tracker path. The page includes the tracker snippet, a server-side curl example, consent opt-out information, and a clear statement that the site ID is public rather than a bearer token.

Regression tests were added for the OpenAPI schema/config boundary and the Core Help page. They are authored and intentionally unrun while the unified plan remains incomplete. Static verification is recorded with this implementation slice.

I13 remains incomplete. The initial centralized credential inventory is implemented, while product-owned create/rotate/revoke actions remain in their source modules. Broader cross-product webhook delivery history and safe retry controls, full route/payload contract fixtures, and representative public-client compatibility rehearsals are still required. Existing machine endpoints and token authorities remain module-owned.
