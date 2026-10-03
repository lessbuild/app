# web/ — the Nuxt app

Nuxt 4 (Vue 3, `<script setup lang="ts">`), server-rendered, Tailwind CSS 4 with the Signal design system. Every page
people see. The repository's [CLAUDE.md](../CLAUDE.md) has the shared rules; this file is what's specific to `web/`.

## Layout

- `app/pages/` — one file per URL. Signed-in pages set `definePageMeta({ layout: 'app' })` (and `service: 'deploy'` etc.
  inside a service); `app/middleware/signed-in.global.ts` then loads the shell for them.
- `app/layouts/app.vue` — the signed-in frame. Sign-in pages draw `AuthFrame` themselves.
- `app/components/` — `signal/` (design-system primitives), `form/` (`ApiForm` and its fields), `shell/`, and one folder
  per feature area. Components are named by file, with no folder prefix.
- `app/composables/` — `useApi` (page data), `useT` (translations), `useShell`, `useForm`.
- `app/utils/` — `client.ts` (`send()`, CSRF), `flash.ts`, `confirm.ts`, `passkeys.ts`, `i18n.ts`, `url.ts`, `icons.ts`.
- `app/types/` — the JSON the API returns.
- `server/middleware/laravel.ts` — proxies Laravel's paths in development (Caddy routes them in production).

## Rules

- Load a page's data with `const { data } = await useApi<T>('/path', () => ({ query }))`. Pass the query as a getter
  so a change to it loads the data again. Don't call `$fetch` against Laravel directly in pages.
- Write with `ApiForm` (form fields with Laravel's names) or `send()` (JSON). Don't hand-roll fetch calls: these two
  handle CSRF, 422 field errors, 423 "Confirm it's you", 409 hand-offs and the flash message.
- Translate with `const { t, tc } = useT()`. Keys are the English text, as Laravel's `__()` keys them.
  `npm run messages` extracts the `t('…')` / `tc('…')` calls in `app/` and fails on a string missing from
  `api/lang/{es,fr,de,pt}.json`. Keep keys as string literals so the script finds them. For sentences with links,
  use `Rich`.
- Dialogs: `UiDialog` / `FormDialog` / `DeleteDialog`, opened with `?dialog=<id>`.
- Check with `npm run lint`, `npm run typecheck` and `npm run build`.
