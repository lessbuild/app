# Frontend dependency modernization

This checkpoint upgrades the existing frontend without changing saved accounts or the application database. It is local verification, not a deployment.

## Dependency state

The npm registry `latest` tags were queried on 2026-09-06. Every direct package is on its latest stable release:

| Package | Installed |
| --- | --- |
| `@playwright/test` | 1.63.0 |
| `@tailwindcss/forms` | 0.5.11 |
| `@tailwindcss/typography` | 0.5.20 |
| `@tailwindcss/vite` | 4.3.3 |
| `alpinejs` | 3.17.1 |
| `axios` | 1.20.0 |
| `laravel-vite-plugin` | 3.2.0 |
| `lodash` | 4.18.1 |
| `tailwindcss` | 4.3.3 |
| `vite` | 8.2.2 |

`npm update` refreshed transitive versions allowed by upstream constraints, including PostCSS 8.5.28 and Rolldown 1.2.7. `npm outdated --json` returns `{}` and `npm audit` reports zero vulnerabilities.

This does not mean that every transitive package has the registry's newest major version. Current upstream dependencies retain their own version constraints: Axios uses https-proxy-agent 5 and agent-base 6; form-data uses asynckit 0.4 and mime-types 2; Tailwind's compiler pins Lightning CSS 1.32 and magic-string 0.30; PostCSS uses nanoid 3; vite-plugin-full-reload uses picomatch 2; typography uses postcss-selector-parser 6. These dependencies have newer releases outside their upstream constraints. No overrides were added to force unsupported compiler or networking combinations.

## Migration

Following the [Tailwind upgrade guide](https://tailwindcss.com/docs/upgrade-guide) and [compatibility guidance](https://tailwindcss.com/docs/compatibility), the dedicated Vite plugin replaces the Sass/PostCSS build pipeline. Sass, Autoprefixer and the direct PostCSS dependency were removed because Tailwind now handles nesting, imports and prefixing. Their old configuration files are superseded by native CSS.

- `resources/css/app.css` is the Vite entry; Blade layouts and the existing asset test reference it.
- CSS theme directives preserve existing grayscale and blue colors, system light/dark preferences, explicit theme classes, and separate background/text/border/placeholder semantics. Surface colors have their own namespace so Tailwind cannot overwrite semantic text colors with background colors.
- Component styles and both existing panel comments were retained. Rounded corners, shadows, outlines, rings, flex shrink/grow and backdrop opacity use the corresponding Tailwind 4 utilities.
- Existing layouts, navigation, tables, forms and the full-width bottom action bar remain in place.

The build requires Node `^20.19.0 || >=22.12.0`, matching [Vite's runtime requirement](https://vite.dev/guide/). Verification used Node 22.12.0 and npm 10.9.0. Tailwind 4 requires Safari 16.4+, Chrome 111+ or Firefox 128+; this raises the browser baseline from Tailwind 3.

## Reproduce asset checks

```sh
npm ci
npx playwright install chromium
npm run test:assets
```

If the shell's default PHP is older than the upgraded development requirements, select a compatible executable without changing other applications:

```sh
BROWSER_PHP_BINARY=/path/to/php npm run test:assets
```

`tests/Browser/fixtures/AssetLayoutFixtureTest.php` renders real public/authenticated Blade pages using the PHPUnit in-memory SQLite connection. It resets Livewire's per-request asset state so each exported page contains the actual Livewire 4 script. The Playwright suite serves fixture HTML and production assets locally, supports Livewire's hashed endpoints, and never submits operations to real services. The HTTP fixture origin does not exercise service-worker registration or PWA delivery.

The matrix covers landing, login, dashboard, configuration upload/history, review and receipt at 320, 390, 768 and 1440 pixels in light/dark mode. Assertions exercise semantic colors, explicit theme overrides, input borders, horizontal overflow, reachable configuration actions, edge-to-edge footer positioning, mobile focus/scroll lock, Escape handling, desktop navigation, command-palette keyboard focus and JavaScript errors. Mobile screenshots are written under ignored `test-results/` for visual review.

## Verified results

- Eight Playwright tests passed in 3.5 minutes, covering all **48 rendered layouts**, with no JavaScript errors. Representative mobile dashboard and configuration review/receipt screenshots were visually inspected across both themes.
- `LocalUiAssetTest` and `DashboardTest`: **35 tests / 725 assertions** passed on PHP 8.5.10, Laravel 13.30.1 and PHPUnit 13.3.2.
- The browser fixture also passed its 13 server-rendering assertions before the layout checks.
- PHP formatting, browser-spec syntax and `git diff --check` passed.
- Clean `npm ci` and production Vite build passed; the rebuilt asset hashes matched the browser-tested build. Generated CSS is 64.33 kB (12.15 kB gzip); Alpine bundle is 56.67 kB (20.00 kB gzip). Build artifacts remain generated/ignored files.

The browser checks verify compiled assets and local layout behavior. They do not establish provider acceptance, deployment, service-worker behavior or full application browser coverage.
