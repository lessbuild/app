# PHP dependency upgrade — 2026-09-06

The application now runs on Laravel 13, Livewire 4, Symfony 8.1, phpseclib 4, and PHPUnit 13. `composer.json` requires PHP 8.5 and stable package releases. The lockfile contains 133 packages. Six installed packages remain below their newest major/minor releases because the latest upstream dependencies constrain them; this is a latest-compatible upgrade, not a claim that every package uses its newest release.

## Verified package versions

Versions were checked against the [Packagist registry](https://packagist.org/) and `composer outdated --all --format=json` on 2026-09-06. The package locks and `composer why-not` output establish compatibility rather than inferred framework support. PHP [8.5.10](https://www.php.net/downloads.php) and [Composer 2.10.3](https://getcomposer.org/download/) were used for resolution and local verification.

| Direct dependency | Locked version | Latest stable |
| --- | --- | --- |
| `fakerphp/faker` | v1.24.1 | v1.24.1 |
| `guzzlehttp/guzzle` | 7.15.5 | 8.2.0 |
| `laravel/cashier` | v16.8.0 | v16.8.0 |
| `laravel/framework` | v13.30.1 | v13.30.1 |
| `laravel/pint` | v1.30.5 | v1.30.5 |
| `laravel/sail` | v1.67.0 | v1.67.0 |
| `laravel/sanctum` | v4.3.3 | v4.3.3 |
| `laravel/socialite` | v5.31.0 | v5.31.0 |
| `laravel/telescope` | v5.23.0 | v5.23.0 |
| `laravel/tinker` | v3.0.2 | v3.0.2 |
| `livewire/livewire` | v4.4.3 | v4.4.3 |
| `mockery/mockery` | 1.6.15 | 1.6.15 |
| `nunomaduro/collision` | v8.9.5 | v8.9.5 |
| `opcodesio/log-viewer` | v3.24.2 | v3.24.2 |
| `phpseclib/phpseclib` | 4.0.1 | 4.0.1 |
| `phpunit/phpunit` | 13.3.2 | 13.3.2 |
| `spatie/async` | 1.8.2 | 1.8.2 |
| `spatie/laravel-ignition` | 2.12.0 | 2.12.0 |
| `spatie/ssh` | 1.13.2 | 1.13.2 |
| `symfony/yaml` | v8.1.6 | v8.1.6 |

## Upstream constraints still unresolved

| Package | Locked | Latest stable | Blocking upstream requirement |
| --- | --- | --- | --- |
| `guzzlehttp/guzzle` | 7.15.5 | 8.2.0 | Socialite 5.31 requires `league/oauth1-client` 1.11, which requires Guzzle `^6.0\|^7.0`. |
| `guzzlehttp/promises` | 2.5.3 | 3.0.2 | Guzzle 7.15.5 requires `^2.5.3`. |
| `guzzlehttp/psr7` | 2.13.1 | 3.1.0 | Guzzle 7.15.5 requires `^2.13.1`; OAuth 1 client requires `^1.7\|^2.0`. |
| `brick/math` | 0.18.0 | 0.20.0 | Laravel 13.30.1 permits up to `^0.19`; Ramsey UUID 4.9.3 permits at most `0.18`. |
| `spatie/error-solutions` | 1.1.3 | 2.0.5 | Latest Spatie Ignition 1.16.0 requires `^1.1.2`. |
| `spatie/flare-client-php` | 1.11.1 | 3.4.3 | Latest Spatie Ignition 1.16.0 requires `^1.9`. |

Laravel itself accepts Guzzle 8; the OAuth 1 client is the limiting package. No fake package aliases, dependency overrides, edited vendor files, or unstable releases were introduced to suppress these conflicts. Replacing/removing upstream integration packages or waiting for compatible releases remains a separate decision. Run `composer why-not PACKAGE VERSION` to recheck each constraint before changing it. The 127 other installed packages were current at the audit.

Primary manifests: [OAuth 1 client 1.11](https://github.com/thephpleague/oauth1-client/blob/v1.11.0/composer.json), [Laravel 13.30.1](https://github.com/laravel/framework/blob/v13.30.1/composer.json), [Ramsey UUID 4.9.3](https://github.com/ramsey/uuid/blob/4.9.3/composer.json), and [Spatie Ignition 1.16](https://github.com/spatie/ignition/blob/1.16.0/composer.json).

## Application compatibility changes

- Migrated native SSH key generation/import consumers and tests from `phpseclib3` to `phpseclib4`; RSA/OpenSSH public/private key round trips remain verified. Consult the [phpseclib migration guide](https://phpseclib.com/docs/intro/migrating) for its separate API and exception changes.
- The application CSRF middleware now extends Laravel 13's `PreventRequestForgery`, retaining all signed provisioning callback and Stripe webhook exclusions. See the [Laravel 13 upgrade guide](https://laravel.com/docs/13.x/upgrade).
- Cache payloads disallow object unserialization; the application stores arrays/scalars. `CACHE_STORE` is supported with backward-compatible fallback to `CACHE_DRIVER`.
- Sessions use JSON serialization. Existing serialized PHP sessions become invalid at rollout, requiring sign-in again. A phased rollout may temporarily set `SESSION_SERIALIZATION=php` to retain existing sessions; remove that override when deliberately switching formats.
- Laravel 13 no longer assumes a login destination for an authentication exception without an explicit redirect. `AuthServiceProvider` configures the session authentication middleware's redirect callback so a revoked browser session returns to login with its intended destination preserved. JSON requests still receive HTTP 401, and revoked session contents are cleared. This preserves the existing password-change, email-change, and other-device revocation flows without changing the exception handler.
- Replaced PHP 8.5's deprecated MySQL SSL attribute constant with `Pdo\Mysql::ATTR_SSL_CA`.
- PHP 8.5 changed `FILTER_FLAG_NO_RES_RANGE`: it accepts documentation IPv6 addresses previously rejected by PHP 8.3. `PublicIpAddress` now uses the global-range filter with an explicit multicast exclusion. Server imports, SSO endpoints, and alert webhooks share it; 18 IPv4/IPv6 regression cases cover public, local, reserved, multicast, and invalid input.
- Livewire now uses hashed asset/update endpoint prefixes (`/livewire-{hash}/…`). The application generates these URLs through Livewire; any external proxy/firewall allowlists must permit the new paths. Review the [Livewire 4 guide](https://livewire.laravel.com/docs/4.x/upgrading).

## Runtime and rollout

No system PHP alternative, FPM pool, Caddy instance, queue worker, or scheduled service was changed. No migration was run against the application's persistent database. The repository's Caddy template now targets the PHP 8.5 socket; applying it requires installing/configuring the corresponding FPM runtime first. Workers and scheduler must use PHP 8.5 too. The daemon installer checks the runtime before any environment edits or migration and supports `BUILDPUSHER_PHP_BINARY`.

A task-local PHP runtime was extracted from the Ubuntu 24.04 PHP maintainer packages into `/root/.local/share/buildpusher/php-8.5.10`; downloaded package checksums were verified against the HTTPS package index. It does not modify the system package database or the default `php`. The wrapper loads its own extension configuration and preserves it for child processes:

```sh
export PATH="/root/.local/share/buildpusher/php-8.5.10/bin:$PATH"
php -v
php /root/.local/share/buildpusher/composer.phar validate --no-check-publish
php /root/.local/share/buildpusher/composer.phar check-platform-reqs
php artisan test
```

Other environments should install PHP 8.5 normally; this machine-specific extracted runtime is not committed or used as a production deployment mechanism. `.php-version` records the exact verified patch version. The six previously pending configuration-as-code migrations remain a separate rollout requirement.

## Upgrade verification

- `composer validate --no-check-publish`: passed.
- `composer check-platform-reqs`: passed against PHP 8.5.10 with required extensions.
- Dependency resolution and optimized autoload/package discovery: passed. `composer audit --format=json` reports no security advisories or abandoned packages.
- `composer install --no-dev --dry-run --no-scripts --no-interaction`: passed without changing installed development packages.
- Daemon installer runtime guard: verified PHP 8.3 exits with an actionable message before mutations; Bash syntax check and formatting/diff checks pass.
- `php artisan test tests/Unit/PublicIpAddressTest.php tests/Unit/SshKeyPairTest.php tests/Feature/ImportServerTest.php tests/Feature/ServerCommandLifecycleTest.php --stop-on-failure`: 37 tests, 207 assertions, all passed. Includes an actual Livewire component update and the server import flow.
- `php artisan test tests/Feature/SessionRevocationTest.php`: 8 tests, 61 assertions, all passed. Covers password/email changes, other-device revocation, browser redirect/intended destination, JSON 401 without redirect, and removal of revoked session contents.
- Existing `AuthenticationRedirectTest` and `BrowserSessionManagementTest`: 9 tests, 61 assertions, all passed after the session redirect correction.

Full application regression and browser evidence are recorded separately with the overall modernization checkpoint; this focused evidence does not establish completion of the entire objective.

## Continuous integration and native method signatures

The inactive template at `docs/ci/verify.yml` defines pull-request, main-branch push, and manual verification on Ubuntu 24.04. It installs the PHP patch in `.php-version`, Composer 2.10.3, and Node.js 24, then checks locked dependencies, security advisories, formatting, production assets, the isolated PHP suite, production dependency resolution, and the isolated Chromium asset-layout suite. The legacy browser crawler requires a separately seeded demo server and is not invoked in this workflow. Actions are pinned to verified release commit IDs. The definition does not deploy or require application/provider secrets; its YAML and action references were checked locally. GitHub refused the active `.github/workflows/verify.yml` path because the connected OAuth credential lacks `workflow` scope, so activation is pending that permission. See [the activation instructions](ci/README.md).

`pint.json` preserves existing PHPDoc tags even after equivalent native types are added, and retains empty PHPDoc blocks. Native return declarations were completed in providers, middleware, and the initialization job, retaining compatible parent signatures. Focused security headers, sensitive rate limiting, sign-in history, initialization retry, and authentication redirect suites pass: 27 tests / 233 assertions. Tinker 3 also booted Laravel 13.30.1 in a non-interactive smoke check.

An independent PHP-Parser AST scan of `app/` after the parallel changes found 423 PHP files and 1,491 class methods with **zero missing native parameter types and zero missing native return types** (constructors/destructors excluded from return requirements). This is a signature audit, not proof of runtime type correctness or complete documentation: 851 methods still had no PHPDoc, and method-body/closure/property analysis was outside this scan.

## Callback transition corrections

Server and website status/failure controllers reload and lock their target inside a transaction before checking the attempt token and current lifecycle state. A callback bound before another request retries, fails, completes, or advances a target can no longer overwrite that newer state. Website preview bookkeeping remains synchronous within the transaction; previous-placement cleanup dispatches after commit. Accepted server/website progress responses remain HTTP 200 and stale callbacks remain HTTP 204.

All three status controllers normalize the validated integer stage before comparing with the terminal stage. Form-encoded callbacks send strings, which previously passed validation but failed strict comparison with the integer terminal stage.

`ProvisioningCallbackConcurrencyTest` passes 23 tests / 195 assertions. It forces deterministic interleavings between route binding and controller execution, covers duplicate completion/failure effects, verifies rollback after failed snapshot/preview persistence, and tests valid/invalid numeric strings for server, website, and build callbacks. These tests reproduce the stale-model sequence in an isolated SQLite test transaction; they do not claim an independent-process database race. Existing callback integrity, lifecycle, server/website retry, and placement suites pass another 22 tests / 169 assertions. The existing preview lifecycle integration suite also passes 2 tests / 23 assertions, for 47 focused callback-related tests / 387 assertions total.
