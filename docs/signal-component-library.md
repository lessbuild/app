# Shared Signal component library

Use `resources/views/components/signal/` as the source of truth for shared Blade UI and `resources/css/signal/` plus `resources/css/components/ui.css` for its semantic theme styles. New product pages use the `x-signal.*` namespace. Product and legacy templates can keep compatibility names while they are migrated; adapters forward props, attributes, slots, and Livewire attributes to the shared implementation.

## Current shared APIs

| Component | Purpose |
| --- | --- |
| `x-signal.layouts.topbar` | Signal Topbar SaaS application shell and its responsive navigation/context strips. |
| `x-signal.layouts.core` | Shared HTML document, theme initialization, assets, Livewire/Alpine runtime, and page slot. |
| `x-signal.layouts.navigation-link` | Active/inactive product and local navigation links. |
| `x-signal.layouts.navigation-group` | Grouped and overflow navigation. |
| `x-signal.layouts.command-palette` | Shared keyboard-search overlay and focus behavior. |
| `x-signal.ui.alert` | Informational, success, warning, and danger feedback. |
| `x-signal.ui.avatar` | Initial-based identity mark with forwarded size and layout attributes. |
| `x-signal.ui.badge` | Neutral, accent, informational, success, warning, and danger status labels. |
| `x-signal.ui.button` | Link and button actions with primary, secondary, ghost, quiet, outline, soft, danger, and inverse variants; small/default/large sizes and disabled-link semantics. |
| `x-signal.ui.card` | Shared panel/card surface with default, muted, and interactive tones. |
| `x-signal.ui.checkbox` | Labeled checkbox input with the shared focus and theme treatment. |
| `x-signal.ui.choice` | Labeled checkbox or radio choice with optional card treatment, descriptions, validation state, and forwarded Livewire attributes. |
| `x-signal.ui.empty-state` | Empty result state with title, description, icon or illustration, action slot, and surface options. |
| `x-signal.ui.field` | Label, visually hidden labels, required marker, description, validation message, error association, and input slot. |
| `x-signal.ui.filter-panel` | Responsive filter disclosure and modal sheet. |
| `x-signal.ui.icon` | Shared inline Signal icon set with forwarded accessibility and size attributes. |
| `x-signal.ui.icon-button` | Accessible icon-only link or button with a required label. |
| `x-signal.ui.input`, `select`, `textarea` | Shared form controls with old-input control, sensitive-value handling, forwarded attributes, and Livewire bindings. |
| `x-signal.ui.insights` | Responsive expandable content surface. |
| `x-signal.ui.local-nav` | Scrollable, labeled product navigation region. |
| `x-signal.ui.menu` | Native disclosure menu with trigger slot and alignment options. |
| `x-signal.ui.page-header` | Page title, eyebrow and icon, description, leading and metadata slots, and actions. |
| `x-signal.ui.stat` | Definition-list metric with label, value, description, icon, and change badge. |
| `x-signal.ui.table` | Captioned, keyboard-scrollable data table with optional header slot and shared framing. |
| `x-signal.ui.workflow-run` | Correlated source/delivery step timeline with states, timestamps, and an optional retry action. |
| `x-signal.ui.workspace-view-form` | Reusable Core form for personal/workspace visibility, product, pinned-only, project-name-contains, and authorized mapped-environment dashboard filters. |
| `x-signal.ui.workspace-project-pin` | Authorized personal/workspace project pin controls built from shared forms and buttons. |
| `x-signal.overlays.modal` | Accessible native dialog with title, description, content, and close behavior. |
| `x-signal.overlays.delete-confirmation` | Reusable delete confirmation composition on the shared dialog and button primitives. |

Use explicit component props for variants and named slots for page-specific content. Cards also accept a constrained semantic element, spacing, and shadow options so a shared panel can remain a `form`, `section`, `article`, or `details` without copying its visual surface. Keep authorization, validation, queries, and domain behavior in their existing controllers, policies, requests, actions, and Livewire components.

## Compatibility adapters

- `x-ui.*` forwards to `x-signal.ui.*`. Existing Deployer screens retain their established component names during migration.
- `x-dialogs.modal`, `x-dialogs.delete`, and `x-dialogs.dialog` forward to `x-signal.overlays.*`.
- Existing layout adapters under `x-layouts.*` forward to `x-signal.layouts.*` where applicable.
- `x-avatar` forwards to `x-signal.ui.avatar`.
- Monitor's `x-monitor::icon` forwards to `x-signal.ui.icon`. Its common `x-monitor::ui` buttons, badges, alerts, cards, panels, forms, tables, metrics, empty states, and page headers remain source-compatible adapters over the same Signal components.

Adapters contain no independent visual styling. Fixes and theme changes belong in the `signal` implementation and shared tokens, not product-specific copies.

## Extension and review

Before adding a component, check this catalog and the Signal template source mapping in `docs/unified-application-plan.md`. Add a shared variant or composition when it is repeated; keep one-off content in the page. Extend the API and gallery with usage, responsive behavior, light/dark and interaction states, and keyboard/accessibility checks. Keep all visual choices in the Signal tokens and shared styles. The complete feature migration still needs a review that every source application UI either uses these components or is represented by a specific product composition built from them.
