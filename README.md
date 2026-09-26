# BuildPusher Platform v2

A single Laravel 13 application that unifies Deploy, Infrastructure, Monitoring and Analytics as services under one account, one dashboard and one bill.

- Plan and decisions: [docs/platform-v2-plan.md](docs/platform-v2-plan.md)
- Working rules for contributors and agents: [CLAUDE.md](CLAUDE.md)

## Local setup

```sh
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm install && npm run dev
php artisan serve
```
