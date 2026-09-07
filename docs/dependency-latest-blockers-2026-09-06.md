# Latest dependency feasibility audit — 2026-09-06

The literal requirement that every installed direct and transitive dependency use its latest stable release is **not satisfied**. The existing PHP graph is unsatisfiable with every package fixed to its latest release. The npm graph also contains incompatible upstream ranges. Removing unused dependencies can reduce the gaps; it cannot resolve the Laravel, Tailwind, and Vite constraints. The modernization goal remains active.

This audit changed no application code, dependency manifests, lockfiles, installed packages, or credentials. Registry snapshots and Composer solver experiments were written under `/tmp` only. The report itself is the only repository change made for this audit.

## Evidence and scope

- Re-ran `composer outdated --all --format=json` with PHP 8.5.10 and Composer 2.10.3, then independently fetched current Packagist manifests. Of 133 installed PHP packages, 127 match the latest stable release and six do not.
- Re-ran `npm outdated --all --json`, then fetched the npm `latest` manifest for all 108 unique package names in `package-lock.json`. Its 121 package instances include 21 entries below the registry's stable `latest` version. Twelve of those entries are installed on this Linux host; nine are optional binaries for other platforms. All ten direct npm dependencies match `latest`.
- Excluded absent optional peers from the gap count. For example, Vite advertises an optional `@vitejs/devtools` range below its latest release, but that package is neither installed nor locked here. Likewise, an absent optional binary at the correct locked version is not an outdated installation.
- Reviewed application imports, Vite entry points, provider implementations, and upstream dependency manifests to distinguish unused packages from packages whose behavior matters.

The raw local evidence is `/tmp/buildpusher-current-composer-audit.json`, `/tmp/buildpusher-latest-php-manifests.json`, `/tmp/buildpusher-current-npm-audit.json`, `/tmp/buildpusher-latest-npm-manifests.json`, and `/tmp/buildpusher-latest-npm-gaps.json`. These temporary files are audit aids, not required project tooling.

## PHP constraints

| Package | Installed | Latest stable | Exact blocking requirement |
| --- | --- | --- | --- |
| `guzzlehttp/guzzle` | 7.15.5 | 8.2.0 | Root currently requires `^7.15.5`. Beyond that editable root range, Socialite 5.31.0 requires OAuth 1 client 1.11.0, which requires Guzzle `^6.0\|^7.0`. |
| `guzzlehttp/promises` | 2.5.3 | 3.0.2 | Guzzle 7.15.5 requires `^2.5.3`; Guzzle 8.2.0 accepts the latest promises package. |
| `guzzlehttp/psr7` | 2.13.1 | 3.1.0 | Guzzle 7.15.5 requires `^2.13.1`; OAuth 1 client requires `^1.7\|^2.0`. |
| `brick/math` | 0.18.0 | 0.20.0 | Laravel 13.30.1 accepts `^0.14.2 \|\| ^0.15 \|\| ^0.16 \|\| ^0.17 \|\| ^0.18 \|\| ^0.19`; Ramsey UUID 4.9.3 requires `>=0.8.16 <=0.18`. Both reject 0.20.0. |
| `spatie/error-solutions` | 1.1.3 | 2.0.5 | Ignition 1.16.0 requires `^1.1.2`. Laravel Ignition 2.12.0 requires Ignition `^1.16`. |
| `spatie/flare-client-php` | 1.11.1 | 3.4.3 | Ignition 1.16.0 requires `^1.9`. |

Primary manifests: [Laravel 13.30.1](https://github.com/laravel/framework/blob/v13.30.1/composer.json), [Socialite 5.31.0](https://github.com/laravel/socialite/blob/v5.31.0/composer.json), [OAuth 1 client 1.11.0](https://github.com/thephpleague/oauth1-client/blob/v1.11.0/composer.json), [Ramsey UUID 4.9.3](https://github.com/ramsey/uuid/blob/4.9.3/composer.json), [Ignition 1.16.0](https://github.com/spatie/ignition/blob/1.16.0/composer.json), and [Guzzle 8.2.0](https://github.com/guzzle/guzzle/blob/8.2.0/composer.json). Latest releases were cross-checked with each package's `https://repo.packagist.org/p2/NAME.json` registry record.

### What can be replaced, and what cannot

**Social login:** BuildPusher supports GitHub, GitLab, and Bitbucket through Socialite's OAuth 2 providers. None of these flows uses the bundled OAuth 1 implementation, but Composer must still honor Socialite's mandatory OAuth 1 dependency. Deleting the OAuth 1 package, pretending to replace it, or merely changing the root Guzzle range cannot produce a valid graph.

Replacing Socialite while retaining all three login and account-connection flows is technically possible. Hybridauth 3.13.0 has no Guzzle dependency and includes those providers. It is not a drop-in migration: session storage must integrate with Laravel; OAuth state, scopes, profile IDs, verified email selection, two-factor continuation, and existing connected account IDs must remain compatible. Its released GitLab adapter still defaults to `/api/v3/` and scope `api`, so it needs an explicitly reviewed adapter configuration or implementation for the current API and existing scope behavior. This is a candidate requiring engineering and tests, not a verified maintained replacement for every existing behavior. Sources: [Hybridauth registry](https://packagist.org/packages/hybridauth/hybridauth), [GitLab adapter](https://github.com/hybridauth/hybridauth/blob/v3.13.0/src/Provider/GitLab.php), [GitHub adapter](https://github.com/hybridauth/hybridauth/blob/v3.13.0/src/Provider/GitHub.php), and [Bitbucket adapter](https://github.com/hybridauth/hybridauth/blob/v3.13.0/src/Provider/BitBucket.php).

The obvious League OAuth 2 replacement does **not** solve this: its latest 2.9.0 release requires Guzzle `^6.5.8 || ^7.4.5`. Symfony's Knp OAuth client bundle depends on that same League client. See [the OAuth 2 client manifest](https://github.com/thephpleague/oauth2-client/blob/2.9.0/composer.json).

**Development exception tooling:** There are no explicit application Ignition/Flare calls or committed Flare configuration, but Laravel Ignition is auto-discovered and supplies real development exception-page behavior. It must not be called unused merely because an import search is empty. Spatie's current Laravel Flare 3.4.1 supports Laravel 13 and Flare client 3.4.3, but Spatie explicitly states that the current Flare client is incompatible with Ignition. The proposed migration therefore needs a deliberate decision about development exception pages, error solutions, sharing, and any external error reporting. Do not enable new telemetry or remove useful debugging behavior just to reduce the outdated count. Sources: [Spatie installation and migration guidance](https://flareapp.io/docs/laravel/general/installation), [Laravel Flare manifest](https://github.com/spatie/laravel-flare/blob/3.4.1/composer.json).

**Math and UUID:** These are genuine framework dependencies. Laravel uses Brick for decimal casts and numeric validation and Ramsey UUID for UUID generation/encoding. Application wrappers cannot change Laravel's Composer constraints. Replacing Ramsey UUID alone still leaves Laravel's own rejection of Brick 0.20.0. The checked Laravel `13.x` and Ramsey `4.x` branch manifests also exclude it; using development branches is not a stable-release solution. Brick 0.20 includes exception behavior changes that deserve upstream compatibility tests. See [Brick's changelog](https://github.com/brick/math/blob/0.20.0/CHANGELOG.md).

For a Laravel application using released upstream packages, the clean completion path for Brick is a compatible Laravel release **and** a compatible Ramsey UUID release. A project-maintained framework/UUID fork or a framework migration would change the maintenance scope substantially and would not make those packages the latest official upstream releases. No such fork, alias, vendor edit, or upstream publication is proposed as an automatic workaround.

## Composer solver experiments

Two isolated manifests were resolved with `composer update --dry-run --no-install --no-scripts --no-plugins --no-interaction --no-progress`. The repository manifest, lockfile, and vendor tree were not used as mutation targets.

1. **Every current PHP package fixed to its latest stable release:** failed with exit 2. Composer reported the Laravel/Brick, Ramsey/Brick, OAuth 1/Guzzle, and Ignition/Flare contradictions. Log: `/tmp/buildpusher-latest-solver-audit/all-latest/solver.log`.
2. **Candidate replacement graph:** replaced Socialite with Hybridauth 3.13.0, replaced Laravel Ignition with Laravel Flare 3.4.1, and requested Guzzle 8.2.0 plus error-solutions 2.0.5. Resolution succeeded with 132 planned packages, including current Guzzle/promises/PSR-7/Flare/error-solutions. Brick remained at 0.18.0. This proves dependency resolution only: the alternative integrations are neither implemented nor behaviorally verified. Explicitly requesting the unused error-solutions package in this experiment does not itself preserve Ignition's solution UI. Log: `/tmp/buildpusher-latest-solver-audit/candidate-replacements/solver.log`.

## npm constraints

The latest upstream parents still contain these ranges; they are not stale root lockfile selections that `npm update` can solve while retaining the existing dependency graph.

| Package or group | Locked | Latest stable | Upstream constraint |
| --- | --- | --- | --- |
| `https-proxy-agent` | 5.0.1 | 9.1.0 | Axios 1.20.0 requires `^5.0.1`. |
| `agent-base` | 6.0.2 | 9.0.0 | The required proxy-agent 5.0.1 requires `6`. |
| `asynckit` | 0.4.0 | 0.5.0 | Form-data 4.0.6 requires `^0.4.0`. |
| `mime-types` | 2.1.35 | 3.0.2 | Form-data 4.0.6 requires `^2.1.35`. |
| `mime-db` | 1.52.0 | 1.54.0 | Mime-types 2.1.35 pins `1.52.0`. |
| `lightningcss` and 11 platform binaries | 1.32.0 | 1.33.0 | Tailwind node 4.3.3 pins Lightning CSS `1.32.0`, whose binary versions are exact. Vite separately resolves a current 1.33.0 copy; that does not upgrade Tailwind's copy. |
| `magic-string` | 0.30.21 | 1.2.3 | Tailwind node 4.3.3 requires `^0.30.21`. |
| `nanoid` | 3.3.18 | 6.0.1 | PostCSS 8.5.28 requires `^3.3.18`; Vite 8.2.2 requires PostCSS. |
| `picomatch` nested under full-reload | 2.3.2 | 4.0.7 | Vite full-reload 1.2.0 requires `^2.3.1`; Laravel Vite plugin 3.2.0 requires that plugin. The other Picomatch copy is current. |
| `postcss-selector-parser` | 6.0.10 | 7.1.6 | Tailwind typography 0.5.20 pins `6.0.10`. |

Registry sources: [Axios](https://registry.npmjs.org/axios/1.20.0), [form-data](https://registry.npmjs.org/form-data/4.0.6), [Tailwind node](https://registry.npmjs.org/@tailwindcss/node/4.3.3), [Tailwind typography](https://registry.npmjs.org/@tailwindcss/typography/0.5.20), [PostCSS](https://registry.npmjs.org/postcss/8.5.28), [full-reload](https://registry.npmjs.org/vite-plugin-full-reload/1.2.0), [Laravel Vite plugin](https://registry.npmjs.org/laravel-vite-plugin/3.2.0), and [Vite](https://registry.npmjs.org/vite/8.2.2).

There are two concrete cleanup opportunities:

- `@tailwindcss/typography` is not registered by `resources/css/app.css`, and no application view uses its `prose` classes. Removing this unused direct dependency would eliminate the selector-parser gap without changing generated application styles, subject to verifying the rebuilt CSS and browser layouts.
- Axios and Lodash occur only in the legacy `resources/js/bootstrap.js`, which is imported only by the inactive `resources/js/app.js`. The configured Vite entries are `resources/css/app.css` and `resources/js/alpine.js`; the active Alpine entry imports the server catalog and runtime logs directly. No application view refers to the inactive entry. Removing these unused dependencies from the active toolchain could eliminate the five Axios-related gaps. Preserve existing source comments and verify the actual built entry graph before making that cleanup. Vendor-published Telescope assets are separate prebuilt bundles; their bundled libraries are not supplied by these root npm dependencies.

The remaining Tailwind/Vite/PostCSS/full-reload constraints require upstream stable releases or deliberate tooling replacements with equivalent CSS generation, source maps, Laravel manifest/hot-file support, and browser refresh behavior. Merely setting `refresh: false` does not remove the Laravel plugin's required full-reload package. Likewise, switching Tailwind's adapter does not automatically remove its shared node compiler dependency. Blanket npm overrides would force versions outside maintained upstream contracts and are not evidence of a supported all-latest graph.

## Concrete next steps and decisions

1. Finish the already authorized application documentation/architecture work and keep the verified current dependency locks while the unsupported combinations remain unresolved.
2. If reducing unused dependencies is included in the modernization scope, remove the inactive typography/Axios/Lodash dependencies in an isolated change and verify the asset graph, production build, and existing 48-layout browser suite. This reduces gaps; it does not complete the literal objective.
3. Decide whether replacing Socialite and Ignition is desirable for actual product/maintenance reasons. If authorized, implement one replacement at a time, preserve login/account linking and developer exception behavior, and prove those behaviors before changing the dependent major versions. The solver experiment provides concrete version targets but does not authorize integration changes or telemetry.
4. Recheck Laravel/Ramsey and Tailwind/Vite/PostCSS manifests when compatible stable releases land. Compatibility work can be prepared locally, but publishing issues, PRs, forks, or packages upstream requires explicit authorization. Do not redefine latest-compatible as latest-stable completion.
5. CI remains independently blocked by GitHub's workflow-write permission. The inactive reviewed template and exact activation steps are in [docs/ci/README.md](ci/README.md). This audit did not inspect token values, change credentials, or attempt another rejected push.

No available combination of the current official stable Laravel, Socialite, Ignition, Tailwind, and Vite packages satisfies an all-latest transitive graph. Some integrations can be replaced with additional engineering, but Laravel's direct Brick constraint alone is sufficient to prevent literal completion while preserving the current released framework.
