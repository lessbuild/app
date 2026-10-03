# BuildPusher Platform v2

One platform that unifies Deploy, Infrastructure, Monitoring, Security and Analytics as services under one account, one dashboard and one bill.

- `api/`: the Laravel 13 application. It serves the API, the admin panel and the public endpoints, and the Blade pages that haven't moved to `web/` yet.
- `web/`: the Next.js frontend (being built; see the plan below).
- Plan and decisions: [docs/platform-v2-plan.md](docs/platform-v2-plan.md); the frontend move and the Audit service: [docs/nextjs-and-audit-plan.md](docs/nextjs-and-audit-plan.md)
- Working rules for contributors and agents: [CLAUDE.md](CLAUDE.md)

## Local setup

```sh
cd api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm install && npm run dev
php artisan serve
```
