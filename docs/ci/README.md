# Activate verification on GitHub

`verify.yml` contains the reviewed verification workflow. It is inactive here because GitHub rejected the active `.github/workflows/verify.yml` path: the connected OAuth credential has repository access but lacks the `workflow` scope.

Once a connection with workflow-write permission is available, copy this file to `.github/workflows/verify.yml`, commit it, and push. It requires no application/provider secrets and does not deploy. It checks PHP 8.5, locked dependencies, formatting, advisories, production assets, the isolated application suite and the 48-layout Chromium suite.

The original local commit with the workflow at its active path is retained on branch `local/modernization-with-ci-20260906`. It need not be merged; copying the template avoids duplicate application changes. Application tests and browser checks passed locally as recorded in [the modernization verification](../laravel-modernization-2026-09-06.md); no GitHub Actions execution is claimed.
