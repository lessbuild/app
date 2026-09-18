# BuildPusher UI usability plan for Luna Max

## Objective

Make BuildPusher easier to read, navigate and operate, especially on a phone.
Reduce the scrolling needed to find information and complete a task. Prioritize
information hierarchy, useful density, clear feedback and accessible controls.

Preserve the existing product capabilities and Laravel/SOLID architecture.
Execute one cohesive, verified slice at a time; document, commit and push each
completed slice before proceeding. This document is a plan, not implementation.

## Inspection reference and evidence

Reviewed September 18, 2026 against `main` at `0d358cf` and the isolated
development deployment at `https://buildpusher.com`.

The review inspected routes, shared layouts, navigation, theme/button/input
styles, page templates, relevant presentation logic and browser specifications.
It rendered 67 route URLs at both 390×844 and 1440×1000: 134 page visits, all
returning HTTP 200. Additional checks covered the guest landing/authentication
flow, selected detail pages, light mode, the command palette and a tablet form.
Some URLs redirect; this is not a claim of 67 independent workflows. Exceptional
states, every role, every modal and physical-device behavior require the fixture
matrix below. No deployment, restore, deletion or provider mutation was run.

Measured heights reflect the current development data and default disclosure
state. Reproduce with fixed fixtures before setting regression assertions.

| Page | Mobile document height | Concrete problem |
| --- | ---: | --- |
| Observability | 12,629px | Server telemetry starts around 5,560px; alert destinations around 9,484px. |
| Notifications | 9,569px | The first notification starts around 1,824px, after filters, saved views and statistics. |
| Dashboard | 7,280px | The main “Needs attention” section starts around 4,469px. |
| Failed deployment | 6,492px | Recovery guidance starts around 5,542px; the log around 6,007px. |
| Active server detail | 5,002px | Metrics, empty diagnostics, websites, recipes, log summaries and setup history all stack. |
| Account | 4,791px | Profile, password, 2FA, history, sessions, connections and deletion share one page. |
| Repository detail | 4,446px | Deployment history starts around 3,955px, below webhook and setup information. |
| Deployment history | 3,246px | Eleven filter controls precede statistics; the first build link starts around 1,857px. |
| Provider creation | 2,207px | Seven stacked provider choices push the token input to approximately 1,055px. |
| Backups | 2,105px | Five large summary values consume most of the first screen; history begins around 1,527px. |

Other verified findings:

- Legacy text colors remain alongside newer semantic tokens. Measured secondary
  text contrast is approximately 3.04:1 on a dark card and 2.13:1 inside dark
  inputs; light-mode secondary text on white is approximately 2.54:1. Dark-mode
  primary buttons use white text on blue at approximately 2.54:1. The footer
  renders black text on a dark surface.
- The desktop sidebar has about 1,548px of content in a 1,000px viewport, with
  many small groups and a second search control alongside the header search.
- The mobile menu retains the requested flat two-section layout. Account and
  Settings currently share a destination; several desktop/mobile labels differ.
- The command palette intercepts arrow keys but does not move focus, expose an
  active descendant or select a result. Its index state is not used by results.
- Sampled application pages all use the browser title `BuildPusher`, which makes
  multiple tabs and browser history difficult to distinguish.
- The workspace page nests two-column form sections inside another two-column
  layout. Its narrow settings form stretches the adjacent member panel into a
  large empty surface.
- Failed deployment detail displays both a milestone timeline and the older
  detailed progress list. Its recorded “Ready” preflight is visually separated
  from the later failure without sufficiently clear time/context.
- Unlimited capacity is drawn as a completely filled usage bar. Empty-state
  illustrations, repeated descriptions and large metric blocks occupy space
  that could show useful content.
- The failed SSH deployment receives “Check source access” guidance because the
  current guidance service relies on numeric setup-stage thresholds. Handle
  this diagnosis defect separately from presentation rearrangement.
- Automation renders its success flash in addition to the shared layout flash.
  Error handling needs particular attention when forms move into closed panels.
- The broad visual browser spec still expects `Account and security` on mobile;
  the dedicated navigation spec expects the restored Account/Settings links.
  Reconcile this test disagreement with the user's chosen navigation.

## Working instructions

Canonical repository reference:

`/root/Documents/Codex/2026-08-30/clone-my-repo-work-on-it/deployer`

Current isolated implementation checkout:

`/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-preview-cleanup-Amr47o`

1. Inspect current `main`, Git status and applicable `AGENTS.md` files. Read
   `docs/CHAT_HANDOFF.md`, `docs/ui-design-system.md`, the existing UI progress
   ledger and this plan. Confirm paths because checkouts may have moved.
2. Work on `main` in an isolated implementation clone, with independent runtime
   configuration, dependencies, assets, storage, caches and test database.
   Verify resolved paths before Artisan. Preserve existing changes.
3. Use `/root/.local/share/buildpusher/php-8.5.10/bin/php`. Keep dependency locks
   unchanged; propose any required new package separately. Retain native types,
   useful PHPDoc and comments; do not introduce `strict_types`.
4. Preserve routes, policy decisions, organization scoping, validation keys,
   error bags, flash messages, secret handling, job payloads and workflow safety.
   Add compatible GET views or allowlisted section parameters where needed.
5. Reuse Blade, Tailwind, Alpine, Livewire and existing `x-ui` components. Do not
   introduce another frontend framework or redo completed extractions.
6. Keep the restored flat mobile menu and text provider selectors. Improve
   their clarity and ergonomics. A replacement menu or removal of mobile links
   is a separate design proposal, not an implicit part of this plan.
7. Keep presentation refactoring and confirmed behavioral bug fixes in separate
   commits. Do not run paid infrastructure operations to verify interface work.
8. Maintain `docs/verification/ui-usability-progress.md`, including the before
   problem, screenshots, affected routes/states, measurements, checks, commit,
   push status, remaining limitations and exact next task.

## Design rules

- Each default page has one clear purpose and primary action. Related settings,
  histories and secondary tools remain easy to reach through local navigation.
- Use compact summaries, selected-resource views and dedicated task pages for
  substantial workflows. Use disclosures for optional details. Avoid hiding
  the whole product inside nested accordions or oversized modal forms.
- Reduce space through hierarchy and layout. Keep readable text and generous
  touch targets. Normal mobile form text should be 16px; primary touch controls
  should target 44px minimum height. Do not disable browser zoom.
- A resource header should identify the resource, application/environment,
  current state and next useful action. Operational failures remain visible.
- Provide a URL for meaningful sections and filters. Refresh, Back, Forward,
  deep links and validation redirects must restore the relevant context.
- Keep ordinary pages in the document scroll flow. Bound logs, code and true
  data tables where necessary. Check clipping as well as page-level overflow;
  `overflow-x-hidden` can conceal a broken layout.
- Prefer ordinary links for server-rendered section navigation. If using an
  actual in-page tab widget, implement the [WAI tab keyboard and ARIA pattern](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/).
- Avoid multiple stacked sticky bars. Any persistent actions must account for
  the existing mobile navigation, safe areas, keyboard and focused fields.
- Collapse optional panels after successful actions only when the result stays
  clear. Open the relevant panel automatically on validation failure.

## Phase 0 — reproducible baseline

Create a route-and-state matrix covering all page families in Phase 7.
Record each page's main task, first useful item/action position, default height,
visible forms, navigation path and screenshot.

Use empty, typical and populated disposable fixtures, including long names,
several environments, multiple providers, 50+ history items, pending operations
and failures. Cover owner, manager/developer and viewer permissions, plus
unavailable integrations and plan limits. Do not manufacture data in the served
development database merely to obtain a screenshot.

Record a current targeted browser baseline. Reconcile the two mobile-navigation
specifications with the restored design. Historical test totals do not prove
this baseline, usability or accessibility.

Exit: every family is inventoried; unexercised states are explicit.

## Phase 1 — readability and shared shell

Start with a small shared-theme slice before restructuring pages.

- Align legacy and semantic text/surface tokens. Fix input text, secondary copy,
  links, primary/danger button text, footer and light-header contrast in normal,
  hover, focus and selected states.
- Reduce excessive all-caps microcopy; standardize body, field, table, heading
  and metadata sizes. Keep headings consistent without making every card loud.
- Give every page a meaningful browser title and clear H1. Extend breadcrumb
  presentation with real resource context where available.
- Improve the desktop sidebar's group spacing and search duplication. Keep
  the existing mobile layout; align terminology and active-state context.
- Complete keyboard selection and activation in the command palette, including
  empty results, bounded arrow navigation, Escape and focus restoration.
- Standardize empty states, feedback and destructive confirmations. Include
  affected resource names and consequences; keep existing server protections.
- Deduplicate automation success feedback as a separate small defect fix.

Use the existing components and theme files. Add a shared primitive only where
there are real repeated consumers.

Exit: readable light/dark screens, identifiable browser tabs and functioning
keyboard navigation; no loss of the restored mobile behavior.

## Phase 2 — results before filters and statistics

Pilot on deployment history, then apply the verified pattern to notifications,
servers, sites, providers, repositories, activity, commands, health/connection
history, sign-ins, reports and search.

- Keep search, one frequent status filter and a `Filters (n)` control visible.
  Put advanced fields behind a disclosure or dedicated accessible filter panel.
- Display applied filter chips, result count and Clear filters beside results.
  Keep filtered URLs, pagination, ordering and CSV semantics intact.
- Move large statistics into a compact summary or optional Insights section.
  Saved views should be a compact selector; creating one is a secondary action.
- Use concise mobile rows with name, state, meaningful secondary context and
  one main action. Expand technical metadata on demand. Prevent badge clipping.
- Show bulk controls when selection is active; preserve a usable no-JavaScript
  submission path and explicit destructive confirmation.
- Preserve return position and filters after visiting a detail page. Avoid
  unbounded infinite scrolling; retain clear pagination.

Exit at 390×844 with typical data: the first deployment, notification, provider
and server result is visible in the initial usable viewport. Document any
necessary exception, such as a blocking security notice. The complete set of
filters remains reachable in one action.

## Phase 3 — dashboard and deployment decisions

Complete the dashboard and deployment-detail slices separately.

Dashboard:

- Put actionable failures, required approvals and active work first.
- Show a small recent-deployments list and concise environment health next.
- Move trends, capacity, community activity and detailed histories into optional
  sections or their existing destinations. Preserve dashboard preferences.
- Summarize healthy/empty categories in one line. Show onboarding when needed.
- Present unlimited quotas as unlimited; do not draw a full utilization bar.

Deployment detail:

- Lead with outcome/current stage, resource/environment, revision, elapsed time
  and the relevant action: approve, cancel, retry, inspect or recover.
- On failure, show the recorded cause and available recovery action immediately.
  Keep retained-release eligibility and database-recovery limitations explicit.
- Present one primary timeline. Make lower-level script progress expandable.
  Default to the active/failed stage; keep completed stages available.
- Separate Overview, Logs, Changes and Approval/history where the data supports
  those sections. Keep logs bounded, searchable and available through existing
  downloads; preserve scroll position while new output arrives.
- Label saved preflight results with their time and scope. Configuration checks
  cannot establish present SSH connectivity or a successful deployment.
- Fix the SSH-failure/source-access guidance mismatch in its own tested commit,
  using reliable recorded failure evidence and a safe fallback for old builds.

Exit: current operational problems and build failure guidance/actions are
visible in the initial mobile viewport. Successful builds remain concise;
approval, cancellation, rollback and stale-update behavior still work.

## Phase 4 — application and resource detail organization

Create a reusable resource-header and local-navigation pattern. Apply it to
applications/environments, repositories, servers, sites and provider detail,
one family per slice.

| Family | Proposed organization |
| --- | --- |
| Applications/environments | Application summary and environment selector; selected environment Overview, Services, Configuration and Settings. Keep an all-environments summary. |
| Repositories | Overview with latest deployment; Deployments, Automation/webhooks and Settings. Completed setup history becomes secondary. |
| Servers | Overview, Sites, Metrics/diagnostics, Logs and Settings; clear access to existing commands and provisioning history. |
| Sites | Overview, Deployments, Domains, Health/logs and Settings; preserve placement and provisioning recovery visibility. |
| Providers | Connection summary and Test connection; Resources, Connection history and Settings. |

Use fewer top-level sections where concepts fit together; avoid squeezing a
long tab strip across a phone. Use an accessible local section selector where
needed. Preserve existing global destinations and canonical URLs.

Configuration authoring should have distinct Edit, Review and Results steps,
with binding assistance next to the edit task. Put prior receipts and
comparisons in secondary views. Preserve desired/recorded/observed distinctions,
review identity, hidden secrets, removal warnings and retry/cancel contracts.

Exit: users can identify the current environment and reach its common task
without scrolling through every other environment or completed setup step.
Existing bookmarks and section/error redirects remain useful.

## Phase 5 — monitoring, recovery and automation

Observability:

- Separate Overview, Incidents, Metrics, Alerting and Status pages with local
  navigation. Avoid stacking every incident before all other capabilities.
- Give incidents compact filterable rows, severity/ownership/status and a
  dedicated detail timeline. Bound and paginate history.
- Put rule/destination/status creation forms behind explicit actions. Keep
  private incident response distinct from public status communication.
- Keep environment context, time range and investigation filters during
  navigation. Show last observation time and stale/missing data explicitly.

Backups:

- Organize around Protection overview, Backup history, Schedules and Destinations.
  Show unprotected sites and the next setup action before empty metric blocks.
- Keep Run backup and Restore reachable from the history context.
- Present destination setup as provider selection, credentials and verification
  with appropriate defaults. Preserve the existing temporary-object connection
  test and show its result next to the destination.
- Show backup completion, connection verification and restore verification as
  separate facts. Replace repeated “Not recorded” cards with compact guidance.
- Keep destination, snapshot and overwrite consequences explicit during restore.

Automation:

- Separate schedules/tasks, runtime/scaling workflows and API tokens.
- Put schedules in the selected application/environment context. Open token
  creation from the token inventory; offer CLI examples as optional help.
- Preserve current token scopes, expiry, plan limits and one-time secret display.
- Keep advanced YAML editing available without making it the default task.

Exit: users can reach alerts, connection verification, restore, schedules or
tokens without scrolling through unrelated workflows. Queued work shows its
pending state and eventual result in the relevant context.

## Phase 6 — forms, account and workspace settings

Provider setup is the first form pilot:

- Retain text provider choices; use compact radio rows or a native select on
  narrow screens. Show relevant provider help alongside the selected choice.
- Show GitHub App guidance when relevant, without displacing unrelated cloud
  provider fields. Keep the GitHub App discovery/connection path available.
- Prioritize required identity and credential fields. Put descriptions and
  detailed monitoring configuration under optional settings, preserving defaults.
- Keep submit actions easy to reach; explain saving versus verifying connection.
  Preserve non-JavaScript POST/redirect/GET and secret-safe failed submissions.

Apply the validated form pattern to application/server/site/repository creation,
imports, backup destinations, DNS, load balancers and database operations.
Use a wizard only when there are genuinely dependent steps. Put advanced commands,
path filters and optional tuning behind clearly named sections.

Account should separate Profile, Security, Sessions, Connected accounts and Data.
Workspace should separate Members/invitations, Security/SSO, Notifications and
General. Use one settings column at a readable width; avoid nested explanatory
columns that squeeze inputs or stretch empty cards.

Provide one error summary linked to fields, inline errors, correct named bags
and automatic reopening of the failing section. Preserve safe non-sensitive
input. Secrets, commands and confidential configuration must not enter new URL
parameters, browser storage, analytics or session old input.

Exit: essential provider fields are visible in the initial mobile viewport;
optional settings remain discoverable. Error recovery works with the mobile
keyboard and a long form; no focused control is hidden by fixed navigation.

## Phase 7 — complete the remaining page families

Apply the established patterns rather than inventing a layout for each page.

| Family | Required improvements |
| --- | --- |
| Databases | Compact inventory and selected-database details; credential issuance and cloning become explicit tasks with consequence review. |
| Domains/TLS | Searchable domain/status list; show actionable DNS/TLS blockers; move add-domain/temporary-domain forms behind actions. |
| Load balancers | Summary of routing and node health; separate node management and configuration; retain apply/cleanup status. |
| Recipes/gallery | Consistent My recipes/Browse navigation, useful search and compact cards; clear install, update and compare actions. |
| Reports/feedback | Put the inbox/history before the submission form; clarify private/public visibility and reporter/contributor actions. |
| Billing/costs | Connect navigation while distinguishing BuildPusher subscription charges, provider estimates and measured utilization. Keep unknown cost explicit. |
| Search | Group results by resource type; show application/environment context; usable empty results and keyboard navigation. |
| System health/admin | Show failing checks and pending review work first; separate technical diagnostics, charts and exports. |
| Public landing/pricing | Clear product journey and primary CTA; consolidate repeated feature blocks; readable plan comparisons and mobile content. |
| Product/API documentation | Local contents navigation, searchable sections, readable code, copy feedback and mobile table containment. |
| Login/registration/password/2FA | Consistent forms, titles, autofill, keyboard behavior, validation and pending/success states. |
| Public status/legal/error pages | Clear current state or recovery action; sensible reading width; usable mobile layout and support reference where applicable. |

Long documents and histories may remain long when that serves their purpose.
Measure access to the task, not an arbitrary page-height ceiling.

## Laravel, SOLID and performance boundaries

- Blade components own repeated presentation; query collaborators/presenters
  provide scoped summaries and section data. Avoid database queries in Blade.
- Controllers coordinate requests and responses. Existing policies, Form
  Requests, actions and jobs remain authoritative for access and writes.
- Load expensive histories/logs when their section is requested. CSS hiding
  alone does not reduce queries or rendering cost. Preserve query bounds,
  pagination and eager loading; avoid new N+1 access in header/tab counters.
- Livewire updates must preserve selected sections, input, focus and scroll.
  Poll only where current work needs it, with bounded refresh and an update time.
- Keep presentation state separate from operational state. A selected tab,
  closed panel or success animation must never change a deployment transition.
- Use concrete collaborators and the existing contracts. No generic repository,
  universal page engine, interfaces for every component or unrelated refactoring.

## Verification and completion

For each slice, record before/after screenshots and task measurements using
the same fixture, viewport and state. Targets:

- Initial mobile viewport contains page identity, the main action and meaningful
  task content. On the pilot inventories, it includes the first result.
- Initial failed-build view includes the reason and recovery/log action.
- Default overview and filter areas shrink substantially; aim for at least 40%
  on the worst baseline examples without removing required information.
- Check 320, 390, 430, 768, 1024 and 1440px widths, light/dark mode, 200% zoom,
  long text and keyboard operation. Validate [reflow at 320 CSS pixels](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html)
  and [focused controls beneath sticky headers/footers](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html).
- Normal text meets [4.5:1 contrast, with applicable large text at 3:1](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).
  Use 44px primary touch targets as the project design goal; this is distinct
  from the [WCAG 2.2 AA minimum target-size criterion and its exceptions](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html).
- Verify role differences, no-JavaScript provider submission, filter URLs,
  pagination, error bags, flashes, secret handling and pending/failure states.
- Browser checks must exercise the task, not merely HTTP 200 or component
  presence. Include clipping detection, palette selection and error-panel focus.
- Run affected PHP/browser tests and the asset build per slice. At the final
  milestone run the full PHP suite, Pint, existing browser coverage, platform
  checks, route/view caching and `git diff --check` in the isolated environment.
- Verify actual served CSS/JS and Livewire assets, including the service worker
  update path. Record physical-phone checks separately from Chromium emulation.
- Update the design-system documentation, handoff and usability ledger. Push
  each verified commit. Explicitly record any untested state or remaining defect.

Start with the reproducible baseline, then the contrast/readability slice.
Proceed to deployment-history filters as the first measurable scrolling pilot.
Do not declare completion because every page has newer cards: demonstrate that
the key tasks are easier to find and complete across the audited page families.
