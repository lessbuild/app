# BuildPusher modal reliability and contextual navigation plan for Luna Max

## Objective

Fix existing modal behavior before adding more dialogs. Short tasks should open on
the invoking page, preserve its context, and close without reloading it. Search,
filters, editors and read-only inspectors should feel like one coherent interface.
Do not turn every destination into a modal.

This is a planning/audit deliverable, not an implementation or deployment record.

## Inspection checkpoint

- Date: 2026-09-20.
- Inspected implementation: isolated `main` at `490b21d`.
- Serving development checkout: `aa84baf` at
  `/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`.
- Service: `buildpusher-dev-main.service`; domain: `https://buildpusher.com`.
- Working checkout:
  `/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-preview-cleanup-Amr47o`.
- Historical repository location:
  `/root/Documents/Codex/2026-08-30/clone-my-repo-work-on-it/deployer`.

Recheck these locations and revisions before execution. Do not assume a pushed
commit is deployed. Do not deploy `490b21d` blindly: the audit found remaining
regressions in that revision too.

## Confirmed findings

| Finding | Evidence | Required response |
| --- | --- | --- |
| Live provider filters allow background scrolling | At 390px, opening filters did not create a native modal; a wheel gesture moved the document from 0 to 500px | Verify the newer filter implementation, then fix its remaining cross-viewport issues |
| Live Edit Provider reloads the document | Clicking emitted a `/providers/8` document request; form visibility took approximately 1.75 seconds in one diagnostic run | Retain an already-mounted shell and fetch only authorized form content |
| Newer provider editor opens without document navigation | Fresh Laravel-rendered fixtures at `490b21d` emitted no document request on opening | Reuse this completed extraction rather than rebuild it |
| Cancel still navigates in the newer provider editor | The Cancel link emitted a `/providers/1` document request | Give all cancel/close controls shared dismissal behavior with a real fallback |
| Browser Forward loses modal state | Back closed the latest editor; Forward restored `?dialog=edit-provider` but left the dialog closed | Reconcile the URL, actual open dialog and history state in both directions |
| Active desktop filters can lock the entire page | At 1440px, the component's active-filter `open` markup produced `overflow: hidden` on HTML/body although the filter was not `:modal` | Do not equate an open inline filter with a blocking modal |
| Mobile filters have no usable no-JavaScript fallback | At 390px with JS disabled, the latest filter button was visible but its inputs were hidden | Preserve accessible GET filter forms before enhancement |
| Dashboard search leaves the page | `dashboard/_quick-actions.blade.php` links directly to `/search`; navigation uses a separate command-palette overlay | Unify these entry points in a workspace-search dialog |
| Some live edit links point at missing shells | The live recipe inventory and backup destinations include edit triggers with no matching mounted dialog | Verify the existing fixes in `9046574`; include every invoking context in regression coverage |
| Notification links lead to unavailable resources | Visible View links returned 404 for `/servers/13`, `/servers/8`, `/websites/7` and `/websites/6` | Preserve concealment/authorization; render a safe unavailable state instead of an actionable dead end |

The desktop active-filter finding is a focused component-markup/browser probe, not
a claim that the older deployed site already uses that implementation. Latency is
one observation, not a performance percentile or service-level measurement.

Existing `asset-layout.spec.js` coverage is useful but not exhaustive route
acceptance: it uses 29 principal fixture paths, returns 204 for unhandled fixture
requests, and substitutes body fragments for several edit endpoints. A matching
`href` or dialog ID is not proof of successful navigation or submission.

### Evidence and coverage limits

The authenticated mobile crawl completed **251 distinct read-only page URL
checks**, inventorying **1,083 distinct href values**. There were 246 successful
responses, four linked unavailable-resource 404s and one deliberately guarded
GitHub-setup 404. The latter was a route-inventory probe, not a visible broken link.
The desktop pass completed 83 page checks (82 successful plus that guarded 404),
followed by eight successful configuration/account-state page checks.
A separate unauthenticated mobile pass checked 12 public/auth routes successfully.
These passes detected no horizontal overflow or page-script exceptions.

The mobile crawl initially lost its browser process and hit a crawler-only form
inspection error. The affected URLs were retried; the completed manifest has no
unresolved harness failures. These were not silently counted as application passes.

Fresh isolated Laravel fixture export passed **1 test / 164 assertions**. Five
focused latest-code probes characterized filter locking, desktop active-filter
markup, Cancel navigation, Forward state and no-JavaScript filters. They are
diagnostics, not a claim that the full regression suite passed this turn.

The href inventory includes modal variants, anchors and external/download links;
it does **not** mean 1,083 links were clicked. Mutations, external authorization,
provider operations, all role/entitlement combinations, every modal variant and
physical-device testing were not exhaustively exercised. Phase 0 must close or
explicitly classify those coverage gaps. Audit artifacts are local to
`/tmp/buildpusher-modal-audit-2KGbMv`; a sanitized route coverage record accompanies
this plan in `docs/verification/modal-audit-2026-09-20.json`.

The uncached route inventory classifies 130 application web GET routes. Of these,
76 had direct browser GET/redirect checks; the remaining 54 are explicitly marked
as not directly visited, including fragments, downloads, protocol routes, remote
reads and state-dependent workflows. No unvisited route is counted as passing.

## Working rules

- Read applicable instructions, `docs/CHAT_HANDOFF.md`, the contextual-navigation
  and modal progress records, current Git status, routes and relevant tests.
- Implement on an independently configured checkout of `main`. Preserve all
  existing work and the acceptance-drill checkout.
- Use `/root/.local/share/buildpusher/php-8.5.10/bin/php` and locked dependencies.
  Keep independent dependencies, assets, app key, caches, storage and test DB.
- Verify resolved runtime paths before Artisan. Inspect route/config caches;
  this checkout contained an older ordinary route cache during the audit.
- Preserve policies, tenant scoping, validation keys/error bags, secret-safe
  failures, flashes, routes, HTTP contracts and queued-operation behavior.
- Do not add `declare(strict_types=1)`, a new frontend framework, generic CRUD
  services or speculative modal abstractions.
- Complete, test, review, document, commit and push one cohesive slice at a time.
  Keep shared bug fixes separate from new modal features.
- No deployment, account changes, paid operations, provider tests, restores,
  emails, cloud creation or destructive actions during a read-only audit.
- When development deployment is authorized, explicitly verify its revision and
  served assets after deployment. Local success is not domain/device acceptance.

Maintain `docs/verification/modal-reliability-progress.md` with the problem,
boundaries, preserved contracts, tests, commit, push/deploy status and next task.

### Laravel and SOLID boundaries

Before each extraction, state the specific responsibility problem and benefit.
Controllers retain HTTP coordination; Form Requests retain validation; policies
retain permission decisions. Shared page/fragment reads belong in existing or
injected query collaborators (single responsibility and dependency inversion).
Reusable Blade components own presentation, and existing actions own writes.
Do not duplicate business logic or invent new actions for a read-only modal.
Adapters/contracts continue to own provider communication; opening an editor is
not a new integration operation. Reuse actual variants without creating an
interface per class or changing transaction, callback, job or retry semantics.

## Phase 0 — accountable page and link coverage

Create a route-and-interaction manifest, not another screenshot-only checklist.

For every page/route family, record:

1. Public/authenticated access, role, entitlement and representative fixture.
2. Every navigation source: header, footer, mobile menu, palette, dashboard,
   cards, empty states, row actions, breadcrumbs, pagination and notifications.
3. Destination, query/hash context and whether it is navigation, a modal, an
   anchor, a download, an external link or an action.
4. Expected policy/status, fallback and redirect behavior.
5. Actual browser check and result, or an explicit reason it was not exercised.

Cover public/legal/docs/auth pages; dashboard/search; applications/environments/
configuration; providers; servers/commands; websites; repositories/deployments;
recipes/gallery/reports; backups; databases; domains/load balancers; automation;
observability/status; notifications/activity; organization/account; costs/billing;
imports and administration.

Preserve query parameters when inventorying links: dialog variants, filters,
selected environments, pagination and return context are different cases. Do not
strip them and then claim their behavior was tested. Test local hash targets too.

Use real isolated Laravel HTTP journeys for routes and submission semantics;
fixtures remain useful for layout and controlled network failures. Unknown fixture
requests must fail or be explicitly classified, not receive a success-shaped 204.

Use disposable populated data for owner/admin, permitted contributor, read-only,
foreign-tenant and denied-plan cases. Do not crawl mutating GET endpoints such as
invitation acceptance, signed email links or OAuth callbacks against saved users.
Test those deliberately in isolation. Never submit every form on the dev domain.

Exit: every route and visible interaction has a classification; untested states
are listed instead of counted as passes. Establish a fresh focused baseline.

## Phase 1 — fix shared modal behavior

### 1A. Filter pilot, then all filter surfaces

Start with providers. Reuse `components/dialogs/modal.blade.php`; present a
centered dialog on desktop and a bottom sheet on narrow screens. Keep the compact
filter trigger and active-filter summary outside it. If a desktop inline filter
is deliberately retained, render it as ordinary nonmodal content.

- Replace the separate filter-overlay lifecycle with the shared dialog behavior.
- Lock background scrolling only while an actual blocking modal is open.
- Scroll the dialog body; keep its heading/close control and actions reachable.
- Support wheel, touch and keyboard scrolling without scroll chaining.
- Restore the exact background position on dismissal, including when opened
  halfway down a long page. Focus restoration must not cause a second jump.
- Active filters must not automatically reopen the sheet after Apply, refresh or
  a viewport change. Reopen only for explicit dialog context or relevant errors.
- Keep filter values, active chips/counts, Clear and GET query semantics.
- Make any existing `href="#...filters"` control open the filter when enhanced.
- Without JS, show a usable inline form or a working filter-page link.
- Test phone/tablet/desktop breakpoint transitions while both open and closed.

Apply the verified behavior to the 11 current shared-filter views: providers,
servers, websites, repositories, recipes, deployments, activity, commands,
gallery, report inbox and my reports. Also classify the custom filters used by
notifications, connection/health histories, sign-ins and observability.

### 1B. Consistent close, history and loading

Keep the existing shared shell and fragment mechanism. Separate dialog lifecycle,
content loading and history synchronization into small cohesive frontend modules
only where that makes the existing responsibilities testable; no universal router.

- Open/close/Cancel must make zero document navigation requests.
- Retain real fallback URLs for direct links, no JS and modified-click/new-tab use.
- Back closes one dialog; Forward reopens it. Refresh/deep links restore its state.
- Preserve background filters, tabs, pagination, selected environment and hash.
- Make direct-open routes without an in-page trigger close safely too.
- Reinitialization after Livewire navigation/content insertion must be idempotent.
- Define dirty-form dismissal; do not warn on unchanged or read-only dialogs.
- Never store entered tokens, environment values or commands in browser storage.
- Show the shell immediately; isolate loading/error/retry inside the dialog.
- Cancel outstanding fetches; reject stale responses and duplicate requests.
- Test 401/403/404/419/422/500, offline, timeout and expired-session responses.
- Never inject a full page or login document into the modal body.
- Avoid overlapping dialogs: close a picker/palette before opening its selected
  task. Explicitly test any unavoidable confirmation overlay and its focus return.

Use appropriate initial focus, contained Tab/Shift+Tab, Escape and return focus.
Reference: [WAI-ARIA modal dialog pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/).
Native `showModal()` provides the browser's modal/top-layer behavior; merely
rendering `open` is not equivalent. Reference:
[MDN showModal](https://developer.mozilla.org/en-US/docs/Web/API/HTMLDialogElement/showModal).

Exit: the reported provider bugs and every confirmed shared regression are
covered by failing-before/passing-after behavioral tests.

### 1C. Repair unavailable notification destinations

Trace the four linked 404 cases through the existing notification destination
resolver. Recheck visibility in the current workspace. If the resource cannot be
accessed, retain the notification but show a neutral unavailable state and a safe
related-inventory link where authorized. Do not claim a resource was deleted when
it may be concealed by authorization. Resolve destinations in bounded batches;
do not add one resource lookup per notification. Keep server-side 404 behavior.

## Phase 2 — fast, context-preserving editors

Verify completed provider/repository/recipe/backup editor extractions first.
Cover every calling page rather than only each feature's inventory.

- Include lightweight reusable shells on the invoking page; do not navigate to
  an inventory to obtain a shell.
- Reuse shared form partials and existing authorized fragment endpoints.
- Do not preload all row forms or secret-bearing content into a large inventory.
- Use unique modal and field IDs; avoid attaching one listener per duplicate
  trigger indefinitely.
- Profile time-to-shell separately from time-to-form, request counts and queries.
  Target immediate shell display within 100ms in the agreed local test setup;
  report measured form latency rather than inventing a universal network target.
- Do not call a provider connection probe merely to open an editor.
- Keep Cancel/X/Escape equivalent and preserve scroll/context.
- Validation errors must remain visible in the originating dialog, not another
  hidden form. Preserve error bags and credential exclusion from old input/logs.
- Keep existing save redirects as the baseline; a partial-update save is a
  separate explicit enhancement with an ordinary HTTP fallback, not a reason to
  duplicate actions or change all response contracts.
- Define fragment invalidation after saves, workspace changes, session expiry or
  permission changes; do not reuse stale privileged content across contexts.

Exit: opening any add/edit link from dashboard, detail pages, inventory, menu,
footer or palette never replaces its background document.

## Phase 3 — one workspace-search dialog

Replace the dashboard's separate navigation with a shared workspace-search shell
included in the authenticated layout. Reuse it from navigation, mobile footer,
mobile menu and Ctrl/Cmd+K. Keep `/search?q=...` as the full results/fallback page.

- Empty input shows existing navigation commands and permitted quick actions.
- Typing searches the existing workspace resource groups. Extract the bounded
  reads in `SearchController` into an injected `WorkspaceSearchQuery` when sharing
  them with a fragment endpoint. Do not create a second search implementation.
- Preserve current organization scoping, LIKE escaping, query length, result
  limits, ordering and "View more" behavior.
- Debounce requests, cancel superseded searches and prevent stale results from
  replacing newer input. Add loading, empty, denied and retry states.
- Support keyboard selection, clear group headings and useful result subtitles.
- Explicitly opening search may focus its input; ordinary mobile page load should
  not summon the keyboard. Keep controls at least 16px on phones.
- Selecting a resource navigates intentionally. Selecting Add/Create closes
  search and opens that task's shell on the original page, without stacked modals.
- Closing restores the original page state. Do not turn every keystroke into a
  browser-history entry or save queries across workspaces.

Exit: all search entry points use one accessible interaction and no search result
or command bypasses existing authorization.

## Phase 4 — add justified contextual dialogs

Implement in the following order, one verified slice at a time. Each inspector
has a canonical "Open full page" destination; paginate or bound its content.

| Surface | Improvement | Important boundary |
| --- | --- | --- |
| Provider detail → connection checks | Connection-history inspector with result/source/date filters | Reuse connection-history query, authorization, ordering and export semantics |
| Website/detail/environment → health checks | Health-history inspector | Preserve retention, pagination and access; no implied SLA claim |
| Dashboard/repository/server/website/provider → deployment history | Context-scoped recent deployments dialog | Main deployment inventory and individual deployment remain full pages; preserve scoped IDs |
| Commands → selected execution | Command detail and bounded output inspector | Never rerun, cancel or fetch remote output merely by opening it; existing downloads stay downloads |
| Automation → completed task output | Read-only task-run output inspector | Enforce ownership; escape text; label truncation; preserve download option |
| Gallery/my reports/notifications → report status | Report-status dialog | Preserve reporter/contributor visibility; opening does not silently mark updates reviewed |
| Notifications → event details | Safe notification-detail inspector with valid related-resource links | Render allowlisted presentation fields, not arbitrary stored payloads; keep marking read explicit |
| Organization → change member role | Short role-edit dialog | Preserve owner protection, permitted role transitions, locks and denial behavior |
| Organization → notification preferences | Compact settings dialog | Preserve preference keys and existing notification behavior |
| Account → edit profile | Profile dialog launched from a compact summary | Preserve profile error bag and email-verification-reset behavior; security setup stays separate |
| Repository → preview push impact | Read-only input/results dialog | Preserve safe paths, unknown-change behavior, limits and no-deploy semantics |

Secondary candidates, only after the above work is verified:

- A bounded build/recipe comparison inspector; wide/large diffs open full page.
- Individual incident/event/webhook-delivery details, not the entire investigation
  dashboard inside a sheet.
- Backup recovery evidence/details, not hidden restore initiation or overwrite
  confirmation. Keep destination and consequences prominent in recovery flows.
- Admin access-request review when repeated inline forms measurably obscure the
  queue; preserve platform-admin gates and notification timing.

Already implemented dialogs to audit and reuse, not recreate: configuration as
code, environment/resource/process/variable composers, preview settings, deployment
controls, webhook settings, website retention, invitations, database credentials,
load-balancer creation/node addition, feedback, tokens/schedules, budget, dashboard
customization, report submission, publishing and script inspection.

Do not convert these wholesale: primary inventories/detail pages, long
configuration reviews/comparisons, imports, restores/clones, provisioning wizards,
interactive terminals, authentication/SSO/OAuth, 2FA/recovery, billing checkout,
public documentation/legal/pricing/status, and destructive operational workflows.
Existing safe confirmations may remain dialogs; irreversible operations still
need explicit authority, context and server-side safeguards.

## Phase 5 — complete regression and served-runtime acceptance

Test each trigger context, not only one dialog per feature:

- 320/390px phones, 768px tablet and 1440px desktop; light/dark and reduced motion.
- Chromium and WebKit where available; explicitly record real-iPhone testing as
  outstanding if no physical device was tested.
- Touch/wheel/keyboard scrolling; long content; on-screen keyboard; safe areas;
  landscape; rotation; zoom; reachable close/save controls.
- Open/X/Cancel/Escape/backdrop rules/Back/Forward/deep link/refresh/new tab.
- Document navigation count, stable background DOM and retained scroll, filters,
  tabs, pagination and environment selection.
- Lazy loading, retry, rapid open-close-switch, duplicate submissions, permission
  revocation, expired session and dirty drafts.
- Valid/invalid submissions in isolation; exact policies/statuses/error bags;
  secret-safe failures; no writes/jobs on denial; no double operations.
- Non-JavaScript routes and forms, including filters and provider submission.
- Query bounds and per-row loading; no N+1 queries or inventory-sized form payloads.

Run focused feature/browser tests per slice; full PHP, full Pint, platform checks,
asset build and `git diff --check` at the final milestone. Verify cached routes and
the actual served Livewire/CSS/JS responses in the isolated runtime.

Then, with development-deployment authority, release the verified revision and
retest the original provider/search journeys on buildpusher.com. Record revision,
asset manifest, response statuses and browser evidence. Updating GitHub alone is
not proof that the user's site changed.

## Completion gate

No unexplained missing targets, unresolved modal-navigation regressions, silent
test exclusions or duplicate business operations. Every inventory item has a
tested outcome or an explicit protocol/security/device/external-acceptance reason.

Update `docs/CHAT_HANDOFF.md`, the coverage manifest and progress ledger. Report
local, deployed-dev, physical-device and external-provider evidence separately.

Start with the provider filter/editor regressions. Complete shared reliability
before workspace search or additional modal features.
