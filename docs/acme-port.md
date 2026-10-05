# Porting the Acme theme's pages into BuildPusher (v3)

Asked 2026-10-05: "The acme theme pages have been updated. Use them pages for Buildpusher. If Buildpusher is missing
something from that page create it. Update all the components and stuff too."

Source: /root/Documents/acme-theme (github.com/lessbuild/acme-theme-), pages under app/pages/platform (the app),
app/pages/landing/stratus (public site), app/pages/help, docs, status, changelog. Data in app/utils/platform*.ts is
sample data: replace it with the API's real data; where the page shows something the API lacks, add it to the API.

Rules: theme components live in web/app/components/acme (prefix Acme). Translate every string (api/lang via
/tmp/add-v3.py). Docblocks on members. No made-up customer quotes or claims on public pages. Commit, push, wait for CI,
deploy with scripts/deploy-preview.sh <run-id> after each phase.

## Phases
- [x] 0 Foundation: re-sync components (incl. Platform*, Audit*, charts), dialogs as right-side drawers, PlatformPage
- [x] 1 Projects: dashboard, new, templates, project overview, setup, domains, settings
- [x] 2 Deploy
- [x] 3 Infrastructure (servers usage/cost, priced size cards, generated-key import, websites cards, LB traffic bars, costs savings, moves checklist)
- [x] 4 Monitoring (uptime strips, pause, incident stats/likely cause/manual resolve, issue trends + stack traces, service map, sparklines, SLO gauges + budget history, release verdicts, status previews, trace hot span, setup checklist)
- [x] 5 Analytics (report in tabbed cards with comparison line, site cards with 30-day visitors, pause collecting, tabbed site page, goal conversions, funnel step bars)
- [x] 6 Security (score ring with 7-day trend, check icons, scan everything, expandable finding rows, status tabs with counts, blocks per day)
- [x] 7 Audit (Acme cards; service stays off)
- [x] 8 Account pages (two-factor and protected-deploy badges), Ask chat with suggestions, notifications
- [x] 9 Public: services menu grouped as in Stratus (Audit joins when enabled), Audit integration copy behind AUDIT_ENABLED; the theme's fictional Audit comparison (made-up competitor and quotes) is not ported

## Page map (Acme → v3)
platform/index → dashboard; projects/new → projects/create; templates → projects/templates;
projects/[project]/{index,setup,domains,settings} → projects/[project]/…;
deploy/* → projects/[project]/deploy/*; infrastructure/* → projects/[project]/infrastructure/*;
monitoring/* → projects/[project]/monitoring/*; analytics/* → projects/[project]/analytics/*;
security/* → projects/[project]/security/*; audit/* → projects/[project]/audit/*; account/* → account/*;
ask → assistant; notifications → notifications.

## Progress notes
- Phase 0 (2026-10-05): scripts/sync-acme.py re-syncs the theme's generic components (re-run it when the theme
  changes; patches fail loudly). Forms keep BuildPusher's fields/dialogs (ApiForm, validation); UiDialog/FormDialog/
  DeleteDialog are right-side drawers with a Cancel/submit footer; command palette stays centred (ui-dialog-center).
  PlatformHeader (components/platform) = the theme's PlatformPage header: title, project switcher, actions, section
  tabs from shell.sectionNav. ProjectHeader wraps it. Sidebar: services as AcmeAppTile tiles (utils/platform.ts
  serviceStyle), one Account tile, notifications; sections are tabs now.
- Phase 1: dashboard (attention list + project health/visitors/last deploy from API), /projects/create replaces
  the new-project wizard dialog (all links point there), templates (cards then form; API adds services + icon),
  project overview (service cards with section links from API), setup guide, domains (inline add, copy record),
  settings (inline add/clone environment, typed delete). SubmitButton has a `disabled` prop.
- Phase 2: Deploy. New API: delivery stats/waiting/recent deploys (DeployActivityQuery), reachable repositories per
  Git provider (GitRepositories + /deploy/repositories/reachable; self-hosted GitLab falls back to typing), webhook
  URL on repositories, environment cards data (protected, lock reason, window, replicas, last deploy, regions),
  preview author (migration; from PR webhooks and branch openers) and deploy counts. UI: repositories, connect page
  (picker), repository (tabs, toggles), deploy (status tiles, inline approval, steps+log, analysis, actions menu),
  compare, environments (cards; settings tab with toggles, day pills, On this page), pipelines (reorderable steps),
  previews (cards, usage bar, branch dialog), configuration (editor beside plan/reviews/bindings). New shared:
  ToggleField, PageTabs restyled as the theme's tabs, StatCard as the theme's stat tile, BuildStatusBadge with dot.
