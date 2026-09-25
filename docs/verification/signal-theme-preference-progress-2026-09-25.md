# Shared Signal theme preference progress

Core stores the signed-in user's Signal appearance choice in the existing `users.preferences` JSON field. The shared layout reads that Core value on every host and supplies a same-origin endpoint on each configured platform host, preserving host-only session cookies. The authenticated endpoint validates `system`, `light`, or `dark`, updates only the `theme` preference inside a Core transaction, and retains unrelated preference keys.

The shared theme bootstrap gives an explicit preview query parameter priority, then applies the authenticated Core preference before consulting host-local storage. The theme control saves changes to the current host, announces saving/success/failure accessibly, and restores the prior appearance if persistence fails. Guest pages keep their existing local-only preference behavior.

Feature and browser regressions are authored for Core rendering, authenticated update, validation, preservation of other preference keys, stale local-storage precedence, and reuse on a second product host. They remain unrun under the plan-wide test deferral. PHP lint, route registration/cache behavior, frontend build, and broader theme/screen/accessibility acceptance remain open for verification.
