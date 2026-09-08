# Provider submission feedback — 2026-09-08

The provider form preselected automatic monitoring on every workspace. `ProviderController` enforces the monitoring entitlement and throws a validation error under `plan` when it is unavailable. The form rendered only field-specific errors, so this error caused a reload without an explanation. The prior native-radio fix corrected provider selection but did not fix this independent submission failure.

## Changes

- New-provider monitoring defaults follow workspace entitlement. The Free-plan form posts monitoring off and explains that manual connection testing remains available. Entitled workspaces retain the enabled default. Existing omitted update settings remain preserved by the request, and explicit unauthorized monitoring requests still fail server-side.
- A shared create/edit error summary displays all validation messages, including plan errors. It is focusable, marked as an alert and requests autofocus after a failed submission.
- Successful create/update redirects display confirmation through the existing flash-message component.
- The exception handler excludes `token` from flashed input, including controller-level validation failures. The saved credential remains encrypted and is not repopulated into the form.

## Verification

Four new regression cases failed before the correction. The final provider feedback, capability and health-monitoring suites pass **20 tests / 156 assertions**. They cover Free-plan creation, exact saved monitoring state, entitled defaults, same-session redirect feedback, plan/field error rendering, encrypted-token storage and exclusion from flash/rendered output. Session cookies are explicitly retained between POST and redirected GET to reproduce browser behavior with JSON session serialization.

The mobile no-JavaScript provider fixture now enables real entitlement enforcement for its Free workspace and checks that the submitted monitoring value is `0`. The Playwright submission test passed at 390px with JavaScript disabled; requests were fulfilled locally.

Production asset build, scoped Pint and `git diff --check` pass. All provider creation in verification uses isolated test data; no live credential, subscription, billing-enforcement setting or cloud resource was changed.
