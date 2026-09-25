# Workspace dashboard operational filters

## 24 September 2026 — saved operational-state filters and per-product rollups

Workspace dashboard views now save one of five operational filters: all states,
needs attention, setup needed, unavailable, or current. Classification uses only
the authorized product activation states, project summaries, and setup steps for
that user's visible recent/pinned cards, plus the existence of an active, mapped
connection failure. Pending/provisioning activations count as setup; failed/error
activations count as attention. An unavailable saved filter fails closed instead
of rendering an empty project set as if it were current.

The workspace totals remain independent of saved filters. Core groups product
activation records by accessible project and product, then shows each app's
active, provisioning, and failed activation counts in the overview and its
product card. This remains a Core activation rollup, not live health or
deployment data. Current product grants and project membership scope the
counts; module activity summaries continue to require active resource mappings
and local product authorization.

Coverage has been authored for attention/setup classification, persistence and
validation of saved state filters, malformed saved filter recovery, and stable
workspace-wide counts when the project preview is filtered. These tests have
not been run, per the user's instruction to defer test execution until the plan
is complete. The broader cross-product operational rollups and complete I7
acceptance remain open.

PHP syntax checks, Pint, Blade view compilation, and `git diff --check` pass for
this slice. No test suite or browser test was run.
