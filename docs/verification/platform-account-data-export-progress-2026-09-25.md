# Core account data export progress — 25 September 2026

The shared account security page now links to an authenticated JSON download on
`auth.buildpusher.com/account/export`. It exports Core-owned profile and
preference data, linked identity metadata, safe passkey metadata, session history,
workspace and project membership, product access, subscriptions for workspaces
the account owns, personal dashboard settings, notification preferences and
read state, feedback submitted by the account, and access-history events tied to
the account.

The response is private and non-cacheable. It omits password and authenticator
secrets, recovery codes, passkey credentials, session hashes, provider tokens and
metadata, workspace settings, payment-provider identifiers, other members'
identifiers, and product operational records. Product operational data remains
available through its owning app's export tools. The export does not delete
records or alter account access.

Focused feature coverage is authored for guest denial, account scoping, useful
membership and billing summaries, encrypted feedback output, and secret
exclusion. It remains unrun under the plan-wide test deferral. Changed PHP syntax,
Pint, account-export route discovery, Blade view compilation, and
`git diff --check` pass. Full product-data portability, account deletion, and
cross-host security acceptance remain open.

Production currently uses Core as the Deployer auth authority, while the old
Deployer account and workspace deletion actions only clean their local records.
Those DELETE routes now return `409` before a local deletion can run whenever
Core authority is active. Their forms are replaced with an explanation, and
Core account security states that coordinated deletion is not available yet.
Legacy-authority behavior remains unchanged. Unit coverage for the guard is
authored and remains unrun under the plan-wide test deferral. This is a safety
guard, not completion of account or workspace deletion: the tracked lifecycle
workflow still needs cross-product cleanup/retention acknowledgements and
recovery behavior.
