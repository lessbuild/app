# BuildPusher platform-v3 — plan

Started 2026-10-03. platform-v2 keeps serving production until v3 replaces it.

## Decisions (owner)

| Topic | Decision |
|---|---|
| Start | **Fresh**, on the orphan branch `platform-v3`, reusing v2's code wherever it fits |
| Frontend | **Nuxt 4** (Vue 3, TypeScript, server-rendered) with **Tailwind CSS 4**, keeping the Signal design. Started in Next.js; switched to Nuxt on the owner's request on 2026-10-03, before any page went live |
| Backend | **Laravel**, following v2's code rules (below), as a JSON API |
| Data | **Empty database**: no carry-over, so the schema may be tidied |
| Admin | **Filament** stays in Laravel at `/admin` |
| Audit | Built, but **off** (`AUDIT_ENABLED=false`) until the production server is upgraded |
| UX | Create and edit screens open as **modals** (with real URLs); multi-step tasks are **wizards**; **improve the UI where necessary** while keeping the Signal look |

## Layout

```
api/    Laravel: the JSON API (/api/app for the frontend, /api/v1 and /api/v2 for tokens), Filament (/admin),
        the public endpoints (ingest, tracker, webhooks, callbacks, CLI, status JSON and badges) and mail
web/    Nuxt: every page people see, signed in or not
deploy/ docs/ sdk/ integrations/
```

## Backend rules (from v2's CLAUDE.md, unchanged)

- Organised by type, grouped by feature area. One single-action controller per route (`__invoke` only), thin:
  validate (Form Request), authorise (Policy), call an Action or Query, respond.
- All writes through Actions (`handle()` only). Reads through Queries returning `final readonly` Data objects.
- External systems behind contracts in `app/Contracts`, faked in tests. Plan limits through `Entitlements`.
- Every method and property documented (summary, `@param`, `@return`; `@var`), enforced by the architecture tests.
- Pint, PHPStan and tests for every slice; commit and push after each.

New for v3:

- Controllers return JSON: Data objects are serialised as they are, so **Data objects hold only scalars, enums, dates,
  arrays and other Data objects — never models** (`App\Data\Concerns\SerializesToJson`). Models never reach a response
  directly.
- The frontend API is `/api/app/*` on the `web` middleware group: the session cookie signs people in, writes carry
  `X-XSRF-TOKEN`. Sign-in, sign-up, two-factor, passkeys and password reset are Fortify in headless mode under
  `/api/app/auth`.
- Validation errors are Laravel's usual 422 JSON; rule violations (`RuleViolation`) map to field errors as in v2.

## Frontend rules

- Pages load data from `/api/app` with `useApi()`, rendered on the server as the signed-in person (the cookies are
  forwarded, and cookies Laravel refreshes are passed back); writes go through `ApiForm` or `send()` (CSRF header,
  422 → field errors, 423 → "Confirm it's you" and retry).
- Signal lives in `web/`: the CSS tokens, presets and component classes (from v2's `resources/css`), the icons, and Vue
  components in `app/components/signal`. Light and dark, palettes, density and contrast all keep working.
- Every user-facing string goes through `t()` and is translated into es, fr, de and pt (`web/messages`).
- Create and edit screens are dialogs on the page linked with `?dialog=<id>` (a full page backs those linked from
  outside the app); multi-step tasks use `Wizard`. Destructive actions confirm in a dialog.
- Accessible by default: labels, focus management in dialogs and wizards, keyboard paths, colour never alone.

## Order

0. **Scaffold**: the Laravel app with v2's domain code (models, migrations, actions, queries, data, services, jobs,
   policies, enums, platform, support, contracts, notifications, Filament) and no Blade pages; the Nuxt app with
   Signal, i18n, the shell, `Wizard`, forms and dialogs. CI for both.
1. **Identity**: sign-up, sign-in, two-factor, passkeys, social and SSO sign-in, email verification, password reset,
   invitations; the shell (`/api/app/shell`).
2. **Projects and account**: dashboard, projects (wizard), setup guide, domains, environments, services; account
   members, roles, API tokens, audit log, webhooks, clients, providers, security, settings; personal settings.
3. **Billing**: plans, usage, invoices, Stripe checkout and portal.
4. **Deploy**, 5. **Infrastructure**, 6. **Monitoring** (incl. telemetry and alerts), 7. **Security**,
   8. **Analytics**, 9. **Status pages**, 10. **Public site** (home, services, pricing, compare, changelog, roadmap,
   help, API docs, legal; SEO), 11. **Recipes, notifications, assistant, onboarding**, 12. **Audit** (off).
13. **Cut-over**: deploy v3 next to v2, switch Caddy, retire v2.
    Before it: security headers for the Nuxt pages (CSP with a nonce for the theme script, HSTS, frame and referrer
    policies; Laravel's SecurityHeaders only covers its own responses), and Caddy routes for `POST` to the email
    links that mail clients unsubscribe with in one click (`laravelPosts` in `web/server/middleware/laravel.ts`).

Each slice moves the area's backend (JSON controllers, requests, tests rewritten as API tests) and its pages together.
