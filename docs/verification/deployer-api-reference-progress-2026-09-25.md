# Deployer API reference progress — 25 September 2026

Core Help now derives Deployer's endpoint list and required token scopes from the same versioned OpenAPI document that the Deployer `/openapi.json` endpoint publishes. The Core page therefore includes configuration planning, review/apply receipts, operation cancellation/retry, deployment logs, rollback, promotion, and existing project/environment operations without maintaining a second manually edited list. Its OpenAPI link and API server URL use the configured Deployer origin. The Deployer endpoint serves the same document with that origin reflected in its `servers` value.

The reference preserves product ownership: Deployer owns the contract, machine endpoints, token issuance, authorization, and execution; Core hosts the shared help page and workspace credential inventory. The Deployer page continues to explain bearer-token scopes, workspace/project restrictions, legacy-token rotation, and its request example.

Regression coverage is authored to check the expanded configuration/retry operations, OpenAPI version, and configured server origin. Tests remain intentionally unrun until the unified plan is complete. PHP syntax checks and Pint passed, the Core help and Deployer OpenAPI routes are registered, and Blade cache compilation succeeded and was cleared.

I13 remains incomplete. Endpoint-by-endpoint route/payload compatibility fixtures, callback/signature review, documented error/rate-limit/revocation/expiry behavior, broader authorized retry controls, and representative real-client rehearsal remain open.
