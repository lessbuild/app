# Native inbox and project infrastructure

Status: source implemented and reviewed; not deployed. Automated regression and browser acceptance remain deferred until all approved plan source work is complete.

## Native notification projection (I3)

Core now merges its workflow summaries with Deployer's recipient-owned database notifications. `WorkspaceNativeNotificationProvider` keeps native read state authoritative: read/unread and bulk-read operations return through the provider, recheck current recipient/resource authority, and update native rows. A source failure is reported without recording a false Core acknowledgement. Existing email and other delivery channels are unchanged.

Deployer resolves the current canonical workspace, native actor/workspace maps, Core grants, native roles, and resource access. Its scoped source query applies visibility before the result limit, so another workspace's recent notifications cannot crowd out the selected workspace. It covers deployment, infrastructure, metric, scheduled task, recipe/gallery, and account notices. Environment-only builds are included; conflicting repository/environment ownership is rejected. Documented legacy notification and recipient class names remain readable during migration.

Proven recipient account/security notices can appear without a project or current Deployer grant, but still require an active Core account/workspace membership. Ordinary project preferences do not suppress security notices. Explicit inbox filters still apply. Core validates provider identity, product, workspace, and supplied project membership, sanitizes displayed text, and anchors resource links to the configured product host. No source payload, notification recipient ID, or encrypted source reference appears in exports.

Monitor has no native database inbox to project; Analytics notifications are delivered through its existing mail channels. Their authorized workflow summaries remain available. This implementation does not invent native inbox records for either product.

## Project resource map (I12)

`ProjectInfrastructureProvider` adds live native relationships to the existing canonical resource map. Core continues to own project/environment/resource mappings; the new projection writes no second ownership store.

The Deployer provider resolves active, exact project/environment mappings and current source policies before returning environments, servers, websites, repositories, and recent deployments. Direct resource restrictions still apply. Shared nodes are deduplicated, and only relationships to returned authorized nodes are included. Source queries apply Core project access before their caps. Core bounds the display to 100 nodes and 300 relationships; recent deployment history is capped at 10 per environment and truncation is disclosed. Selected or unavailable environment context cannot expand the scope.

The shared Signal map uses reusable cards, badges, buttons, disclosure, and table components. It includes a semantic resource list and relationship list for keyboard access. A native outage displays an unavailable state rather than cached labels or links. Existing Monitor application/environment and Analytics site mappings and workflow links remain in the canonical map.

## Deferred acceptance

The integrated source slice passed sequential PHP syntax checks for 170 changed/new PHP files, resolved application-class reference checks, the module-boundary check, Blade compilation and syntax checks for 516 compiled templates, and the Vite build. Explicit-path Pint completed successfully. Compiled development views were cleared afterward. These were static/build checks; no automated test suite, migration, or live deletion workflow was executed.

Authored tests cover native and Core read-state authority, lost product access, security notices, category and legacy-class compatibility, workspace result limits, source failures, partial bulk updates, stale/foreign provider output, current infrastructure grants, selected environments, direct resource restrictions, shared-resource privacy, bounded history, outage states, and the accessible resource list. Automated tests have not been run.

Before release, execute those regressions with separate native databases and verify signed-in cross-host destinations, state changes after revocation, browser back/refresh behavior, mobile layout, keyboard access, and the full plan's feature-parity matrix. Static compilation and formatting checks do not establish that acceptance.
