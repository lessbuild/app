# Shared Signal component library

Use `resources/views/components/signal/` as the source of truth for shared Blade UI and `resources/css/signal/` plus `resources/css/components/ui.css` for its semantic theme styles. New product pages use the `x-signal.*` namespace. Product and legacy templates can keep compatibility names while they are migrated; adapters forward props, attributes, slots, and Livewire attributes to the shared implementation.

The upstream Signal source is `https://github.com/lessbuild/template` on `main`. The latest source checked for this integration is `1dfa5aa5a699229b16c429d165d3833b5457281b` (`Add release approval and incident follow-up previews`, 2026-09-24). Its theme stylesheet is byte-for-byte aligned with this app; its component stylesheet is also aligned, with Laravel validation and code-block rules added locally. The shared stylesheet includes Signal's current comparison-chart, command-palette, and Topbar SaaS navigation treatments. The Laravel page-header follows Signal's responsive composition and retains application-specific icon, metadata, and action slots. The application topbar adapts Signal's two-row SaaS shell to live workspace, project, environment, and product navigation. Signal adds release-approval and incident-follow-up examples; Deployer keeps its real approval and incident workflows and uses authorized product data. This application currently uses native disclosure menus rather than Signal's `data-popover-toggle` behavior. The side-sheet behavior is adapted into `x-signal.overlays.side-sheet` and `x-signal.overlays.side-sheet-trigger`; Deployer's project view uses them to navigate its environments. The record-detail composition maps to live project-detail screens, which use authorized product data rather than static sample records. The product drawer begins below the live topbar height so Deployer's workspace and project context remains visible; its height follows the responsive topbar as content wraps. Deployer stores dashboard widget visibility in the user profile; template-only browser storage and sample metrics are not used for product data. The latest grouped activity inbox remains a template example, so product activity continues to use authorized module records and delivery state.

## Current shared APIs

| Component | Purpose |
| --- | --- |
| `x-signal.layouts.topbar` | Signal Topbar SaaS application shell and its responsive navigation/context strips. |
| `x-signal.layouts.core` | Shared HTML document, theme initialization, assets, Livewire/Alpine runtime, and page slot. |
| `x-signal.layouts.navigation-link` | Active/inactive product and local navigation links. |
| `x-signal.layouts.navigation-group` | Grouped and overflow navigation. |
| `x-signal.layouts.mobile-sidebar` | Responsive Signal drawer surface with a backdrop, focus management, escape dismissal, focus return, and breakpoint-aware close behavior. |
| `x-signal.layouts.mobile-navigation` | Server-rendered product navigation composed from product, workspace, project, environment, account, and support destinations. |
| `x-signal.layouts.command-palette` | Shared keyboard-search overlay and focus behavior. |
| `x-signal.ui.alert` | Informational, success, warning, and danger feedback. |
| `x-signal.ui.avatar` | Initial-based identity mark with forwarded size and layout attributes. |
| `x-signal.ui.badge` | Neutral, accent, informational, success, warning, and danger status labels. |
| `x-signal.ui.button` | Link and button actions with primary, secondary, ghost, quiet, outline, soft, danger, inverse, link, and stateful variants; small/default/large sizes and disabled-link semantics. |
| `x-signal.ui.card` | Shared panel/card surface with default, muted, and interactive tones. |
| `x-signal.ui.checkbox` | Labeled checkbox input with the shared focus and theme treatment, optional unchecked value, and old-input restoration control. |
| `x-signal.ui.code-block` | Horizontally scrollable code sample styled with shared Signal emphasis, border, radius, and code-font tokens. |
| `x-signal.ui.choice` | Labeled checkbox or radio choice with optional card treatment, descriptions, validation state, unchecked values, old-input control, and forwarded Livewire attributes. |
| `x-signal.ui.empty-state` | Empty result state with title, description, icon or illustration, action slot, and surface options. |
| `x-signal.ui.field` | Label, visually hidden labels, required marker, description, validation message, error association, and input slot. |
| `x-signal.ui.filter-panel` | Responsive filter disclosure and modal sheet. |
| `x-signal.ui.icon` | Shared inline Signal icon set with forwarded accessibility and size attributes. |
| `x-signal.ui.icon-button` | Accessible icon-only link or button with a required label. |
| `x-signal.ui.input`, `select`, `textarea` | Shared form controls with old-input control, sensitive-value handling, forwarded attributes, and Livewire bindings. |
| `x-signal.ui.input-field`, `select-field`, `textarea-field` | Labeled Signal controls that compose the field, control, description, required state, validation message, and accessible error associations. |
| `x-signal.ui.insights` | Responsive expandable content surface. |
| `x-signal.ui.local-nav` | Scrollable, labeled product navigation region. |
| `x-signal.ui.menu` | Native disclosure menu with trigger slot and alignment options. |
| `x-signal.ui.panel` | Shared semantic panel surface for `div`, `section`, `article`, `aside`, `form`, `fieldset`, and `details` compositions. |
| `x-signal.ui.page-header` | Responsive page title, optional breadcrumbs and title anchor, eyebrow and icon, description, leading and metadata slots, and actions. |
| `x-signal.ui.stat` | Definition-list metric with label, value, description, icon, and change badge. |
| `x-signal.ui.table` | Captioned, keyboard-scrollable data table with optional header slot and shared framing. |
| `x-signal.ui.workflow-run` | Correlated source/delivery step timeline with states, timestamps, and an optional retry action. |
| `x-signal.ui.workspace-view-form` | Reusable Core form for personal/workspace visibility, product, pinned-only, project-name-contains, and authorized mapped-environment dashboard filters. |
| `x-signal.ui.workspace-project-pin` | Authorized personal/workspace project pin controls built from shared forms and buttons. |
| `x-signal.overlays.modal` | Accessible native dialog with title, description, content, and close behavior. |
| `x-signal.overlays.side-sheet`, `side-sheet-trigger` | Accessible contextual sheet with backdrop, focus containment and return, Escape dismissal, and preserved scroll locking across overlays. |
| `x-signal.overlays.dialog-shell` | Shared native dialog and panel shell for product-specific content and Livewire-owned actions. |
| `x-signal.overlays.delete-confirmation` | Reusable delete confirmation composition on the shared dialog and button primitives. |

Use explicit component props for variants and named slots for page-specific content. Cards also accept a constrained semantic element, spacing, and shadow options so a shared panel can remain a `form`, `section`, `article`, or `details` without copying its visual surface. Keep authorization, validation, queries, and domain behavior in their existing controllers, policies, requests, actions, and Livewire components.

`x-signal.layouts.command-palette` accepts `searchUrl` for private asynchronous resource results, optional `searchActionUrl` for a normal GET results page on Enter, and `extraItems` for product-specific shortcuts. Deployer uses this shared palette while keeping its application/server/site/repository creation dialogs and operations links. The latest Signal mobile drawer replaces the Deployer mobile popover while preserving product switching, workspace switching, project/environment context, product sections, support, and logout.

## Compatibility adapters

- Deployer views now call `x-signal.ui.*` and `x-signal.overlays.*` directly, including the shared page-header on its application screens and dashboard. The `x-ui.*`, `x-dialogs.*`, and `x-layouts.partials.heading` adapters remain available for compatibility with any unmigrated consumers.
- `x-dialogs.modal`, `x-dialogs.delete`, and `x-dialogs.dialog` forward to `x-signal.overlays.*`.
- Existing layout adapters under `x-layouts.*` forward to `x-signal.layouts.*` where applicable.
- `x-avatar` forwards to `x-signal.ui.avatar`.
- Monitor's `x-monitor::icon` forwards to `x-signal.ui.icon`. Its common `x-monitor::ui` buttons, badges, alerts, cards, panels, forms, tables, metrics, empty states, and page headers remain source-compatible adapters over the same Signal components.

Adapters contain no independent visual styling. Fixes and theme changes belong in the `signal` implementation and shared tokens, not product-specific copies.

## Extension and review

Before adding a component, check this catalog and the Signal template source mapping in `docs/unified-application-plan.md`. Add a shared variant or composition when it is repeated; keep one-off content in the page. Extend the API and gallery with usage, responsive behavior, light/dark and interaction states, and keyboard/accessibility checks. Keep all visual choices in the Signal tokens and shared styles. The complete feature migration still needs a review that every source application UI either uses these components or is represented by a specific product composition built from them.
