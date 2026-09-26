# Shared Signal component library

Use `resources/views/components/signal/` as the source of truth for shared Blade UI and `resources/css/signal/` plus `resources/css/components/ui.css` for its semantic theme styles. New product pages use the `x-signal.*` namespace. Product and legacy templates can keep compatibility names while they are migrated; adapters forward props, attributes, slots, and Livewire attributes to the shared implementation.

The upstream Signal source is `https://github.com/lessbuild/template` on `main`. The latest source checked for this integration is [`794d273ebd4635ff124981f0fcddce89e9d7c2b1`](https://github.com/lessbuild/template/commit/794d273ebd4635ff124981f0fcddce89e9d7c2b1) (`Add social, job search, and finance page sets`, 2026-09-25). It includes Signal's Buildpusher suite and product-detail compositions (`template-buildpusher.njk`, `template-buildpusher-product.njk`, and `product-marketing.njk`) alongside the other landing templates. The Buildpusher suite and product pages adapt those compositions to live Buildpusher marketing routes, shared authentication, actual product subdomains, and the configured feature catalog. Reusable Signal blocks render product cards, the project-to-app map, FAQs, calls to action, and each app's clearly labeled fictional interface preview. The page previews describe existing capabilities but do not present sample metrics or activity as live product data. Buildpusher keeps real workspace identity, theme defaults, and authorization server-backed and does not import browser-only workspace creation or brand storage. The shared stylesheet retains Signal's product accents and responsive composition, comparison-chart, command-palette, and Topbar SaaS navigation treatments plus Laravel validation, code-block, and status-dot additions. The Laravel page-header follows Signal's responsive composition and retains application-specific icon, metadata, and action slots. The application topbar adapts Signal's two-row SaaS shell to live workspace, project, environment, and product navigation. Its product navigation follows Signal's `xl` breakpoint and hidden-scrollbar utility, keeping the mobile drawer available through 1279px while preserving live workspace and project context. Signal adds release-approval and incident-follow-up examples; Deployer keeps its real approval and incident workflows and uses authorized product data. This application currently uses native disclosure menus rather than Signal's `data-popover-toggle` behavior. The side-sheet behavior is adapted into `x-signal.overlays.side-sheet` and `x-signal.overlays.side-sheet-trigger`; Deployer's project view uses them to navigate its environments. The record-detail composition maps to live project-detail screens, which use authorized product data rather than static sample records. The product drawer begins below the live topbar height so Deployer's workspace and project context remains visible; its height follows the responsive topbar as content wraps. Deployer's `x-layouts.app` name is only a compatibility entry point: it renders the shared Signal document, Topbar SaaS shell, command palette, and shared asset runtime. Its surfaces and controls are also componentized in the direct `x-signal.ui.*` namespace; no separate Deployer theme is loaded. Deployer stores dashboard widget visibility in the user profile; template-only browser storage and sample metrics are not used for product data. The latest grouped activity inbox remains a template example, so product activity continues to use authorized module records and delivery state.

The 2026-09-25 source recheck confirmed that upstream `main` still resolves to `794d273ebd4635ff124981f0fcddce89e9d7c2b1`. The suite landing preview follows Signal's current release, service-health, and traffic composition in `x-signal.blocks.product-suite-preview`; Analytics' illustrative product preview also adapts Signal's progressive 7-, 30-, and 90-day range control. Both previews use shared Signal surfaces and controls, with sample data explicitly labeled.

## Current shared APIs

| Component | Purpose |
| --- | --- |
| `x-signal.layouts.topbar` | Signal Topbar SaaS application shell and its responsive navigation/context strips. |
| `x-signal.layouts.core` | Shared HTML document, theme initialization, assets, Livewire/Alpine runtime, and page slot. |
| `x-signal.layouts.navigation-link` | Active/inactive product and local navigation links. |
| `x-signal.layouts.navigation-group` | Grouped and overflow navigation. |
| `x-signal.layouts.mobile-sidebar` | Responsive Signal drawer surface with a backdrop, focus management, escape dismissal, focus return, and breakpoint-aware close behavior. |
| `x-signal.layouts.mobile-navigation` | Server-rendered product navigation composed from product, workspace, project, environment, account, and support destinations. |
| `x-signal.layouts.mobile-quick-navigation` | Compact Deployer destinations and primary create action, composed from shared links and buttons. |
| `x-signal.layouts.command-palette` | Shared keyboard-search overlay and focus behavior. |
| `x-signal.ui.alert` | Informational, success, warning, and danger feedback with block-level `div`, `p`, `section`, or `aside` semantics. |
| `x-signal.ui.avatar` | Initial-based identity mark with forwarded size and layout attributes. |
| `x-signal.ui.badge` | Neutral, accent, informational, success, warning, and danger status labels. |
| `x-signal.ui.button` | Link and button actions with primary, secondary, ghost, quiet, outline, soft, danger, inverse, link, and stateful variants; small/default/large sizes and disabled-link semantics. |
| `x-signal.ui.card` | Shared panel/card surface with default, muted, and interactive tones, including semantic links, figures, definition lists, and list items. |
| `x-signal.ui.checkbox` | Labeled checkbox input with the shared focus and theme treatment, optional unchecked value, and old-input restoration control. |
| `x-signal.ui.code-block` | Horizontally scrollable code sample styled with shared Signal emphasis, border, radius, and code-font tokens. |
| `x-signal.ui.choice` | Labeled checkbox or radio choice with optional card treatment, descriptions, validation state, unchecked values, old-input control, and forwarded Livewire attributes. |
| `x-signal.ui.empty-state` | Empty result state with title, description, icon or illustration, action slot, and surface options. |
| `x-signal.ui.field` | Label, visually hidden labels, required marker, description, validation message, error association, and input slot. |
| `x-signal.ui.flash-messages` | Session feedback rendered through the shared alert component with status semantics. |
| `x-signal.ui.filter-panel` | Responsive filter disclosure and modal sheet. |
| `x-signal.ui.icon` | Shared inline Signal icon set with forwarded accessibility and size attributes. |
| `x-signal.ui.icon-button` | Accessible icon-only link or button with a required label. |
| `x-signal.ui.input`, `select`, `textarea` | Shared form controls with old-input control, sensitive-value handling, forwarded attributes, and Livewire bindings. |
| `x-signal.ui.input-field`, `select-field`, `textarea-field` | Labeled Signal controls that compose the field, control, description, required state, validation message, and accessible error associations. |
| `x-signal.ui.insights` | Responsive expandable content surface. |
| `x-signal.ui.link` | Themed navigation link with inline, stacked, or block layout and compact, standard, inline, or no-size preset. |
| `x-signal.ui.local-nav` | Scrollable, labeled product navigation region. |
| `x-signal.ui.menu` | Native disclosure menu with trigger slot and alignment options. |
| `x-signal.ui.panel` | Shared semantic panel surface for links, paragraphs, definition lists, list items, and `div`, `section`, `article`, `aside`, `form`, `fieldset`, and `details` compositions. |
| `x-signal.ui.page-header` | Responsive page title, optional breadcrumbs and title anchor, eyebrow and icon, description, leading and metadata slots, and actions. |
| `x-signal.ui.progress` | Accessible progress bar or meter with clamped value, range, label, and shared Signal track/fill styling. |
| `x-signal.ui.stat` | Definition-list metric with label, value, description, icon, and change badge; slot mode wraps compact custom metrics in a shared Signal surface. |
| `x-signal.ui.status-dot` | Token-colored small or large operational status indicator. |
| `x-signal.ui.settings-section` | Responsive settings section with a shared card surface and optional mobile disclosure. |
| `x-signal.ui.table` | Captioned, keyboard-scrollable data table with optional header slot and shared framing. |
| `x-signal.ui.workflow-run` | Correlated source/delivery step timeline with states, timestamps, and an optional retry action. |
| `x-signal.ui.workspace-view-form` | Reusable Core form for personal/workspace visibility, product, pinned-only, project-name-contains, and authorized mapped-environment dashboard filters. |
| `x-signal.ui.workspace-project-pin` | Authorized personal/workspace project pin controls built from shared forms and buttons. |
| `x-signal.overlays.modal` | Accessible native dialog with title, description, content, and close behavior. |
| `x-signal.overlays.backdrop-button` | Accessible label-required button surface for dismissing navigation and modal overlays. |
| `x-signal.overlays.side-sheet`, `side-sheet-trigger` | Accessible contextual sheet with backdrop, focus containment and return, Escape dismissal, and preserved scroll locking across overlays. |
| `x-signal.overlays.dialog-shell` | Shared native dialog and panel shell for product-specific content and Livewire-owned actions. |
| `x-signal.overlays.delete-confirmation` | Reusable delete confirmation composition on the shared dialog and button primitives. |

Reusable public-site blocks include `x-signal.blocks.public-navigation`, `product-card`, `product-feature`, `product-preview`, `product-suite-preview`, `product-connections`, `product-explorer`, `workspace-capability`, `faq-list`, and `cta`. `product-card` accepts a constrained product accent and an icon from the shared Signal icon component. `product-preview` composes a product-specific illustrative UI from Signal surfaces and accents, with sample-data labeling and configurable heading IDs for repeated previews. `product-suite-preview` mirrors Signal's shared project overview with a sample Deployer release, Monitor health view, and Analytics traffic estimate. `product-connections` renders the shared-project relationship as links to the real product-description routes; it does not imply that a resource is connected in a customer's workspace. `product-explorer` presents the live product capability summaries with a keyboard-accessible tab enhancement and functional in-page links when JavaScript is unavailable. `workspace-capability` presents Core workspace tools with the same Signal card and icon primitives.

Use explicit component props for variants and named slots for page-specific content. Cards also accept a constrained semantic element, spacing, and shadow options so a shared surface can remain a link, list item, paragraph, `summary`, `form`, `section`, `article`, or `details` without copying its visual surface. Keep authorization, validation, queries, and domain behavior in their existing controllers, policies, requests, actions, and Livewire components.

`x-signal.layouts.command-palette` accepts `searchUrl` for private asynchronous resource results, optional `searchActionUrl` for a normal GET results page on Enter, and `extraItems` for product-specific shortcuts. Deployer uses this shared palette while keeping its application/server/site/repository creation dialogs and operations links. The latest Signal mobile drawer replaces the Deployer mobile popover while preserving product switching, workspace switching, project/environment context, product sections, support, and logout.

## Compatibility adapters

- Deployer views and their shared public/authenticated shell now call `x-signal.ui.*`, `x-signal.overlays.*`, and `x-signal.layouts.*` directly, including the shared page-header on its application screens and dashboard. The `x-ui.*`, `x-dialogs.*`, and `x-layouts.partials.heading` adapters remain available for compatibility with any unmigrated consumers.
- Every Deployer module view is guarded against reintroducing the old `x-ui.*`/`x-dialogs.*` namespace. The `x-layouts.app` name is a compatibility entry point that loads the same shared Signal document, Topbar SaaS shell, theme runtime, and stylesheet used by the other app modules.
- Deployer's raw card/panel surfaces now compose `x-signal.ui.card` or `x-signal.ui.panel`; the architecture regression checks that app views do not reintroduce raw `ui-card`/`ui-panel` wrappers or HTML form controls.
- Deployer feedback, badges, progress bars, operational status dots, and compact dashboard metrics now use shared Signal components; the architecture regression also rejects their raw product-owned wrappers.
- Monitor's page surfaces, alerts, status badge, and progress bars use shared Signal components. Its hidden and visible inputs use the shared input component, and its auth icon action and compatibility command dialog use Signal components. Its architecture regression rejects raw surfaces, feedback, and controls; remaining `x-monitor::ui` elements are forwarding components over the shared Signal implementations.
- Analytics' 23 remaining Blade views pass the same raw surface, status, control, and legacy namespace source scan; an architecture regression now protects that boundary. Its behavioral and authenticated-screen acceptance remains separately tracked in the feature parity matrix.
- Core's product views now use shared Signal inputs for hidden request state as well as visible controls. The Core view architecture regression covers surfaces, status primitives, controls, overlays, and compatibility aliases.
- The Buildpusher public overview uses the current Signal product-marketing treatment, shared product accents and icons, and the reusable project-to-app map. Its app descriptions and routes are server-rendered from the product catalog.
- `x-dialogs.modal`, `x-dialogs.delete`, and `x-dialogs.dialog` forward to `x-signal.overlays.*`.
- Existing layout adapters under `x-layouts.*` forward to `x-signal.layouts.*` where applicable.
- `x-avatar` forwards to `x-signal.ui.avatar`.
- Monitor's `x-monitor::icon` forwards to `x-signal.ui.icon`. Its common `x-monitor::ui` buttons, badges, alerts, cards, panels, forms, tables, metrics, empty states, and page headers remain source-compatible adapters over the same Signal components.

Adapters contain no independent visual styling. Fixes and theme changes belong in the `signal` implementation and shared tokens, not product-specific copies.

## Extension and review

Before adding a component, check this catalog and the Signal template source mapping in `docs/unified-application-plan.md`. Add a shared variant or composition when it is repeated; keep one-off content in the page. Extend the API and gallery with usage, responsive behavior, light/dark and interaction states, and keyboard/accessibility checks. Keep all visual choices in the Signal tokens and shared styles. The complete feature migration still needs a review that every source application UI either uses these components or is represented by a specific product composition built from them.
