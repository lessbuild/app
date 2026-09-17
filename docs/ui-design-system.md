# BuildPusher UI design system

This document describes the shared presentation vocabulary introduced during
the UI modernization. It is deliberately small: existing routes, controllers,
Livewire components and behavior do not depend on these classes.

## Principles

- Use semantic surfaces and text tokens for new markup so light and dark mode
  remain paired.
- Prefer one page header, one breadcrumb treatment and one action hierarchy.
- Keep primary actions obvious, secondary actions quiet and destructive actions
  explicit.
- Preserve the existing `primary`, `secondary`, `tertiary` and `ternary`
  button classes while views migrate. They are compatibility aliases, not a
  reason to rewrite unrelated pages.
- Keep status colors supplemental to text and icons; never communicate state
  by color alone.
- Preserve keyboard focus, visible validation feedback and touch targets of at
  least 40px for interactive controls.

## Shared primitives

Presentation-only anonymous Blade components live under `x-ui`:

| Component | Use |
| --- | --- |
| `x-ui.page-header` | Page title, optional icon, description and `actions` slot |
| `x-ui.button` | Semantic `primary`, `secondary`, `ghost`, `danger` or `inverse` action |
| `x-ui.card` | Bordered surface with optional `muted` or `interactive` tone |
| `x-ui.badge` | Compact `neutral`, `accent`, `success`, `warning` or `danger` state |
| `x-ui.empty-state` | Empty collection guidance with an optional `action` slot |

Existing layout partials now use the shared header, breadcrumb, stat, alert and
empty-state styles. New pages should use those partials or the primitives
directly instead of creating a one-off equivalent.

## Migration guidance

1. Preserve the existing controller data and route contract.
2. Replace repeated presentation markup only after checking its responsive and
   accessibility behavior.
3. Use `button--primary` through `x-ui.button` for new blue primary actions;
   migrate historical `.button.primary` usages only when their visual intent is
   confirmed.
4. Keep domain-specific status colors and copy in the view, but use
   `x-ui.badge` when the same compact status treatment appears more than once.
5. Record any intentional information hierarchy or responsive behavior change
   in the UI modernization progress ledger.
