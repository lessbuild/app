# Method contracts and recovery-code verification — 2026-09-07

This follow-up preserves the configuration-as-code implementation and the modernization checkpoint at `ed9182c`. It completes the missing application method documentation and fixes one recovery-code acceptance race discovered while reviewing those contracts. Dependency manifests and lockfiles are unchanged.

## Documentation coverage

The PHP parser inventory covers **423 application PHP files and 1,491 class methods**. All 1,491 methods now have PHPDoc; there are zero missing native parameter types and zero missing native return types, excluding constructors/destructors from the return requirement. This pass added **851 missing method docblocks** and expanded 40 existing contracts. An additional existing recipe-validation return annotation was corrected to include its rule objects instead of claiming string-only rules.

Coverage includes controllers, requests, middleware, Livewire components, responses, services, actions, jobs, commands, notifications, observers, provider contracts, data objects, policies, scripts and support helpers. The existing model, scope, presenter, enum and listener documentation remains in place. New documentation describes actual inputs, outcomes, failures, ownership checks and side effects; native types remain the source of runtime enforcement. No `strict_types` declaration was added.

The documentation changes touch 273 application files. Comparing resolved PHP syntax trees with `ed9182c` found no behavioral differences outside `TwoFactorAuthentication::verifyUser`, described below. Two HTTP files gained imports used only by PHPDoc (`Collection` and Cashier `Checkout`). Original comment lines were retained when expanding documentation; the inaccurate recipe-rule return type is the explicit correction. No methods or executable implementation were removed by the documentation pass.

Local audit artifacts are `/tmp/buildpusher-doc-pass-verification.json`, `/tmp/buildpusher-http-preservation.json`, `/tmp/buildpusher-owned-contract-verification.json`, and `/tmp/buildpusher-contract-docs-verification.json`. These are temporary inspection aids; the coverage and preservation findings are recorded here so the checkpoint does not depend on their continued existence.

## Recovery-code correction

Previously, recovery-code verification checked a hash on a loaded user, then locked and reloaded that user before removing the hash. The method ignored the transaction's outcome and returned true even when another request had already consumed the code. Two stale user instances could therefore both accept one recovery code.

Consuming verification now returns the locked membership/removal result: a missing hash fails, and acceptance requires the removal to save successfully. Ordinary TOTP verification and the explicitly non-consuming inspection path retain their behavior. This changes only `TwoFactorAuthentication::verifyUser` and its contract; no authentication routes, session configuration or stored hash format change.

Three regressions cover two stale consumers, explicitly non-consuming verification, and TOTP verification preserving recovery codes. The stale-consumer regression was run against the previous implementation first and failed because the second consumer incorrectly returned true. It passes with the fix, including reuse of the first stale instance and independent consumption of the remaining code.

## Verification

Full-repository `pint --test` and `git diff --check` passed. The final recipe-annotation correction also passed a scoped formatter check.

The four relevant authentication suites passed **21 tests / 157 assertions**, exit zero:

```sh
/root/.local/share/buildpusher/php-8.5.10/bin/php artisan test \
  tests/Feature/TwoFactorAuthenticationTest.php \
  tests/Unit/TwoFactorAuthenticationTest.php \
  tests/Feature/AuthenticationRedirectTest.php \
  tests/Feature/SessionRevocationTest.php
```

Tests use the repository's isolated test database configuration. The earlier full modernization suite remains **1,165 tests / 10,643 assertions** at `ed9182c`, with 48 browser layouts; those totals are prior evidence, not a claim that the entire suite was rerun after this follow-up. Documentation was checked through formatting, parser inventory, executable-token comparison, resolved syntax-tree comparison and comment preservation. The authentication suites exercise the single behavioral change.

See [the dependency feasibility audit](../dependency-latest-blockers-2026-09-06.md) for the remaining upstream version conflicts. All-latest dependency completion remains unresolved. No deployment, real database migration, paid-provider operation or active GitHub workflow installation is included in this checkpoint.
