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
