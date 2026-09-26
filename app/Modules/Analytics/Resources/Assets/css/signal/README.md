# Signal provenance

Analytics uses the Signal starter's application shell, centered authentication pattern, typography, focus treatment, theme switcher, and semantic component vocabulary.

The source snapshot used during the Laravel adaptation is:

- `/root/Documents/Codex/2026-09-21/plan-can-you-create-a-tailwindcss/src/styles/theme.css`
- `/root/Documents/Codex/2026-09-21/plan-can-you-create-a-tailwindcss/src/styles/components.css`
- `/root/Documents/Codex/2026-09-21/plan-can-you-create-a-tailwindcss/src/layouts/app.njk`
- `/root/Documents/Codex/2026-09-21/plan-can-you-create-a-tailwindcss/src/layouts/centered.njk`
- `/root/Documents/Codex/2026-09-21/plan-can-you-create-a-tailwindcss/src/components/app-sidebar.njk`

Buildpusher's graphite palette is the selected Signal preset. The runnable token mapping lives in `resources/css/app.css`; this directory records the provenance so later theme changes can be reviewed against the original starter. Analytics-specific styles remain in `app.css`, and no Signal demo service worker or PWA cache is shipped.
