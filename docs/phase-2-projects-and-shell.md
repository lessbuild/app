# Phase 2: projects and shell

Design notes for Phase 2 of [the plan](platform-v2-plan.md). Journeys come first; each slice below is built, tested and pushed on its own.

## Model

- **Project** belongs to an account. It has a name, a slug that is unique within the account, and an optional description. URLs use the project's ULID (`/projects/{id}`), so links keep working after a rename and across accounts. Opening a project in another of your accounts switches your current account to it.
- **Environment** belongs to a project. It has a name, a slug and a kind (`production`, `staging`, `development`, `preview`). Every project is created with a Production environment. A project always keeps exactly one production environment, and it can't be deleted.
- **Domain** belongs to a project and optionally to one environment. Hostnames are normalised: lowercase, IDN to ASCII, no scheme, port or path. Ownership is proven with a DNS TXT record at `_buildpusher.<hostname>`, checked through a `DnsResolver` interface in `app/Services/Dns`. A verified hostname can belong to only one project on the platform, while unverified claims can coexist.
- **Services** come from the `PlatformService` registry: key, name, description, icon, the project nav items and the API scopes. Deploy, Infrastructure, Monitoring and Analytics are registered now as shells. Their features arrive in Phase 4, and tiers and prices in Phase 3.
- **Enabled services**: a project turns services on and off (`project_services`). Turning a service off keeps its data. Deleting the data is a separate, explicit step that belongs to each service in Phase 4.
- **Per-service member access**: a membership has an optional list of services it may use (`memberships.service_access`, null = all). Owners and admins always have every service. The role still decides view versus manage; the list only narrows which services a person can use.

## Journeys

1. **Sign up → first project → domain → services.** After registering (or accepting an invitation to an account with no projects), the dashboard points to "Create your first project". The create form asks only for a name. It creates Production and opens the project's overview, whose checklist says: add a domain (optional), then pick services. Each service card on the overview has an Enable button.
2. **Enable a service from inside a project.** The project sidebar lists enabled services, plus "Add a service". A service that isn't enabled opens its enable page: what it does, and an Enable button (a tier picker in Phase 3). Enabling it returns you to that service's page.
3. **Invite a teammate.** Account → Members (done in Phase 1). New in Phase 2: an optional "Only these services" choice on invite and on a member's row.
4. **Upgrade one service**: Phase 3.
5. **Incident → release → traffic impact**: Phase 4.

## Shell

- **Topbar**: brand, account switcher, project switcher, search/command palette (⌘K), notifications, user menu (Settings, Sign out). Ported from the old branch's Signal `topbar`, `workspace-switcher` and `command-palette` layouts. The JS (`signal-topbar.js`, `signal-shell.js`) is already in v2.
- **Project sidebar**: Overview, the enabled services and their nav items, Environments, Domains, Settings.
- **Account sidebar**: Projects, Members, API tokens, Audit log, Settings.
- **Mobile**: the same navigation in the Signal drawer.

## Slices

1. Projects, environments, service registry, enabled services, per-service access (domain + policies + tests) with plain pages.
2. The shell: topbar, switchers, project and account sidebars, mobile drawer; the interim `layouts.app` header goes.
3. Domains with DNS verification.
4. Onboarding checklist and the empty-dashboard journey.
5. Activity feed and notifications inbox.
6. Search / command palette.
