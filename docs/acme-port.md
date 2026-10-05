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
- [ ] 0 Foundation: re-sync components (incl. Platform*, Audit*, charts), dialogs as right-side drawers, PlatformPage
- [ ] 1 Projects: dashboard, new, templates, project overview, setup, domains, settings
- [ ] 2 Deploy
- [ ] 3 Infrastructure
- [ ] 4 Monitoring
- [ ] 5 Analytics
- [ ] 6 Security
- [ ] 7 Audit
- [ ] 8 Account pages, Ask, notifications
- [ ] 9 Public: Stratus home/service/pricing/compare updates, help centre

## Page map (Acme → v3)
platform/index → dashboard; projects/new → projects/create; templates → projects/templates;
projects/[project]/{index,setup,domains,settings} → projects/[project]/…;
deploy/* → projects/[project]/deploy/*; infrastructure/* → projects/[project]/infrastructure/*;
monitoring/* → projects/[project]/monitoring/*; analytics/* → projects/[project]/analytics/*;
security/* → projects/[project]/security/*; audit/* → projects/[project]/audit/*; account/* → account/*;
ask → assistant; notifications → notifications.

## Progress notes
