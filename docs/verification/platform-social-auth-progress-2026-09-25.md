# Shared social authentication progress — 25 September 2026

Core now provides GitHub, GitLab, and Bitbucket sign-in and account linking from
`auth.buildpusher.com`. Provider IDs are stored in Core's existing
`user_identities` table; Deployer, Monitor, and Analytics continue to use the
same canonical Core identity. No schema change or database migration is needed.

The flow resolves an exact `(provider, provider user ID)` mapping before checking
email. A matching email never links accounts automatically. New accounts require
a verified provider email, honor the registration gate, and receive an initial Core
workspace. Invitation-based signup checks the invitation's normalized email and
returns to its page without accepting it automatically. GitLab uses the current
v4 user endpoint and an email is accepted only when the account is confirmed.
Account linking is a
deliberate authenticated action with current-password and configured
authenticator-code checks; identity ownership conflicts are rejected. Unlinking
is blocked if it would remove the account's last usable sign-in method. OAuth
tokens are not persisted.

Focused Core tests cover exact identity resolution, no email-based linking,
social account/workspace creation, cross-account ownership rejection, and last
sign-in-method protection. Tests remain unrun under the standing instruction to
wait until the full plan is complete.

Production provider configuration is absent for all three providers. The shared
login screen therefore shows no social buttons, while account security labels
providers as unavailable. To enable one, set its client ID and secret in the
shared environment and register its exact callback URL:

- `https://auth.buildpusher.com/social/callback/github`
- `https://auth.buildpusher.com/social/callback/gitlab`
- `https://auth.buildpusher.com/social/callback/bitbucket`

Real provider-console, callback-state, invitation, recovery-code/MFA, and
cross-host acceptance remain open. Existing provider IDs imported into Core are
read from the canonical identity table; legacy Deployer columns remain available
for rollback and reconciliation until the overall cutover is complete.
