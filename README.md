# BuildPusher platform v3

- `api/` — the Laravel API, admin panel and public endpoints.
- `web/` — the Nuxt app.
- Plan: [docs/plan.md](docs/plan.md). Working rules: [CLAUDE.md](CLAUDE.md).

## Local setup

```sh
cd api && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate && php artisan serve
cd web && npm install && npm run dev   # http://localhost:3000, proxies Laravel's paths to :8000 (LARAVEL_URL)
```
