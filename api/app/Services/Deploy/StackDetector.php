<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Deploy\DetectedApp;

/**
 * Finds the apps in a repository from its files: the top level, each folder in it, and each folder under apps/ and
 * services/. For each app it works out the framework and version, the runtime that deploys it, what it needs (a
 * database, a queue worker, the scheduler, Redis) and its build and start commands. Installing dependencies isn't
 * included: deploys already run Composer, npm, pnpm, Yarn and pip. Notes are written in English, as translation keys,
 * so they can be stored and shown in each person's language.
 */
final class StackDetector
{
    /**
     * The frameworks it recognises, by key, with the name people know them by.
     *
     * @var array<string, string>
     */
    public const FRAMEWORKS = [
        'laravel' => 'Laravel', 'symfony' => 'Symfony', 'wordpress' => 'WordPress', 'php' => 'PHP',
        'nuxt' => 'Nuxt', 'next' => 'Next.js', 'astro' => 'Astro', 'sveltekit' => 'SvelteKit', 'remix' => 'Remix', 'static' => 'Static site', 'node' => 'Node.js',
        'django' => 'Django', 'flask' => 'Flask', 'fastapi' => 'FastAPI', 'rails' => 'Ruby on Rails', 'docker' => 'Docker', 'compose' => 'Docker Compose',
    ];

    /**
     * Top-level folders that hold part of an app, never an app of their own.
     *
     * @var list<string>
     */
    private const NOT_APPS = ['app', 'bin', 'bootstrap', 'config', 'database', 'doc', 'docs', 'lang', 'lib', 'node_modules', 'packages', 'public', 'resources', 'routes', 'scripts', 'src', 'storage', 'test', 'tests', 'vendor'];

    /**
     * List the apps in a repository, backends (PHP and Python) first, then by folder.
     *
     * @param  RepositorySnapshot  $files
     * @return list<DetectedApp>
     */
    public function detect(RepositorySnapshot $files): array
    {
        $apps = [];
        foreach ($this->folders($files) as $folder) {
            $app = $this->app($files, $folder);
            if ($app !== null) {
                $apps[] = $app;
            }
        }
        usort($apps, fn (DetectedApp $a, DetectedApp $b): int => [! $a->isBackend(), $a->path] <=> [! $b->isBackend(), $b->path]);

        return $apps;
    }

    /**
     * The folders that could hold an app: the top level, each top-level folder, and each folder under apps/ and
     * services/ (where monorepos keep theirs).
     *
     * @param  RepositorySnapshot  $files
     * @return list<string>
     */
    private function folders(RepositorySnapshot $files): array
    {
        $folders = [''];
        foreach ($files->paths as $path) {
            $parts = explode('/', $path);
            if (count($parts) < 2 || str_starts_with($parts[0], '.') || in_array($parts[0], self::NOT_APPS, true)) {
                continue;
            }
            $folders[] = in_array($parts[0], ['apps', 'services'], true) && count($parts) >= 3 ? $parts[0].'/'.$parts[1] : $parts[0];
        }

        return array_values(array_unique(array_filter($folders, fn (string $folder): bool => ! in_array($folder, ['apps', 'services'], true))));
    }

    /**
     * Work out the app in one folder, or null when there isn't one (a library, documentation, or nothing deployable).
     *
     * @param  RepositorySnapshot  $files
     * @param  string  $folder
     * @return DetectedApp|null
     */
    private function app(RepositorySnapshot $files, string $folder): ?DetectedApp
    {
        $at = fn (string $file): string => ltrim($folder.'/'.$file, '/');
        $composer = $files->json($at('composer.json'));
        $package = $files->json($at('package.json'));
        if ($composer !== null) {
            return $this->php($files, $folder, $composer, $package);
        }
        if ($files->has($at('wp-config-sample.php')) || $files->has($at('wp-config.php')) || $files->has($at('wp-content'))) {
            return new DetectedApp($folder, 'wordpress', 'WordPress', 'php', database: 'mysql');
        }
        if ($package !== null && ($node = $this->node($files, $folder, $package)) !== null) {
            return $node;
        }
        if (($python = $this->python($files, $folder)) !== null) {
            return $python;
        }
        $gemfile = (string) $files->read($at('Gemfile'));
        if (preg_match('/^\s*gem\s+["\']rails["\']/m', $gemfile) === 1) {
            return $files->has($at('Dockerfile'))
                ? new DetectedApp($folder, 'rails', 'Ruby on Rails', 'docker', port: 3000)
                : new DetectedApp($folder, 'rails', 'Ruby on Rails', 'docker', port: 3000, notes: [__('Rails deploys as a container: add a Dockerfile (Rails 7.1 and later generate one).')]);
        }
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'] as $compose) {
            if ($files->has($at($compose))) {
                return new DetectedApp($folder, 'compose', 'Docker Compose', 'compose');
            }
        }
        if ($files->has($at('Dockerfile'))) {
            return new DetectedApp($folder, 'docker', 'Docker', 'docker', port: $this->exposedPort((string) $files->read($at('Dockerfile'))));
        }

        return null;
    }

    /**
     * Describe a PHP app: Laravel (with what it needs), Symfony, WordPress or plain PHP.
     *
     * @param  RepositorySnapshot  $files
     * @param  string  $folder
     * @param  array<string, mixed>  $composer
     * @param  array<string, mixed>|null  $package
     * @return DetectedApp
     */
    private function php(RepositorySnapshot $files, string $folder, array $composer, ?array $package): DetectedApp
    {
        $at = fn (string $file): string => ltrim($folder.'/'.$file, '/');
        $require = array_merge((array) ($composer['require'] ?? []), (array) ($composer['require-dev'] ?? []));
        $php = $this->version((string) ($require['php'] ?? ''), 2);
        $build = $package !== null && isset($package['scripts']['build']) ? $this->packageManager($files, $folder).' run build' : null;
        if (isset($require['laravel/framework'])) {
            $major = $this->version((string) $require['laravel/framework'], 1);
            $env = $this->dotenv((string) $files->read($at('.env.example')));
            $database = strtolower($env['DB_CONNECTION'] ?? ((int) $major >= 11 ? 'sqlite' : 'mysql'));
            $console = (string) $files->read($at('routes/console.php')).(string) $files->read($at('bootstrap/app.php')).(string) $files->read($at('app/Console/Kernel.php'));
            $horizon = isset($require['laravel/horizon']);
            $redis = $horizon || isset($require['predis/predis']) || isset($require['ext-redis'])
                || in_array('redis', [strtolower($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? ''), strtolower($env['SESSION_DRIVER'] ?? ''), strtolower($env['QUEUE_CONNECTION'] ?? '')], true);

            return new DetectedApp(
                $folder, 'laravel', trim('Laravel '.$major), 'php', $php,
                database: match ($database) {
                    'mariadb' => 'mysql',
                    'postgres', 'postgresql' => 'pgsql',
                    default => $database,
                },
                queue: $horizon || $files->hasUnder($at('app/Jobs')) || ($files->hasUnder($at('app/Notifications')) && strtolower($env['QUEUE_CONNECTION'] ?? 'database') !== 'sync'),
                scheduler: preg_match('/Schedule::|->schedule\(|withSchedule|\$schedule->/', $console) === 1,
                redis: $redis,
                buildCommand: $build,
                notes: $horizon ? [__('It uses Horizon: run php artisan horizon as its worker instead of queue:work.')] : [],
                workerCommand: $horizon ? 'php artisan horizon' : 'php artisan queue:work --tries=3 --max-time=3600',
            );
        }
        if (isset($require['symfony/framework-bundle'])) {
            return new DetectedApp($folder, 'symfony', trim('Symfony '.$this->version((string) $require['symfony/framework-bundle'], 1)), 'php', $php, database: null, buildCommand: $build);
        }
        if (isset($require['johnpbloch/wordpress']) || isset($require['roots/wordpress'])) {
            return new DetectedApp($folder, 'wordpress', 'WordPress', 'php', $php, database: 'mysql');
        }

        return new DetectedApp($folder, 'php', 'PHP', 'php', $php, buildCommand: $build);
    }

    /**
     * Describe a JavaScript app by its framework, or null for a package with nothing to run (a library or tooling).
     *
     * @param  RepositorySnapshot  $files
     * @param  string  $folder
     * @param  array<string, mixed>  $package
     * @return DetectedApp|null
     */
    private function node(RepositorySnapshot $files, string $folder, array $package): ?DetectedApp
    {
        $at = fn (string $file): string => ltrim($folder.'/'.$file, '/');
        $dependencies = array_merge((array) ($package['dependencies'] ?? []), (array) ($package['devDependencies'] ?? []));
        $scripts = (array) ($package['scripts'] ?? []);
        $manager = $this->packageManager($files, $folder);
        $build = isset($scripts['build']) ? "{$manager} run build" : null;
        $node = $this->version((string) ($package['engines']['node'] ?? ''), 1) ?? $this->version(trim((string) $files->read($at('.nvmrc')), " v\n"), 1);
        $version = fn (string $name): string => (string) $this->version((string) ($dependencies[$name] ?? ''), 1);
        $static = fn (string $label, string $output): DetectedApp => new DetectedApp($folder, 'static', $label, 'node', $node, buildCommand: $build,
            startCommand: "npx --yes serve@14 --single {$output} --listen tcp://127.0.0.1:\$PORT", port: 3000, notes: [__('A static build, served by a small file server.')]);

        return match (true) {
            isset($dependencies['nuxt']) => new DetectedApp($folder, 'nuxt', trim('Nuxt '.$version('nuxt')), 'node', $node, buildCommand: $build, startCommand: 'node .output/server/index.mjs', port: 3000),
            isset($dependencies['next']) => new DetectedApp($folder, 'next', trim('Next.js '.$version('next')), 'node', $node, buildCommand: $build, startCommand: "{$manager} run start", port: 3000),
            isset($dependencies['@sveltejs/kit']) => isset($dependencies['@sveltejs/adapter-node'])
                ? new DetectedApp($folder, 'sveltekit', 'SvelteKit', 'node', $node, buildCommand: $build, startCommand: 'node build', port: 3000)
                : $static('SvelteKit', 'build'),
            isset($dependencies['@remix-run/node']) || isset($dependencies['@remix-run/serve']) => new DetectedApp($folder, 'remix', 'Remix', 'node', $node, buildCommand: $build, startCommand: "{$manager} run start", port: 3000),
            isset($dependencies['astro']) => isset($dependencies['@astrojs/node'])
                ? new DetectedApp($folder, 'astro', trim('Astro '.$version('astro')), 'node', $node, buildCommand: $build, startCommand: 'node ./dist/server/entry.mjs', port: 4321)
                : $static(trim('Astro '.$version('astro')), 'dist'),
            isset($dependencies['express']) || isset($dependencies['fastify']) || isset($dependencies['hono']) || isset($dependencies['koa']) || isset($dependencies['@nestjs/core']) || isset($scripts['start']) => new DetectedApp($folder, 'node', 'Node.js', 'node', $node, buildCommand: $build, startCommand: isset($scripts['start']) ? "{$manager} start" : null, port: 3000),
            isset($dependencies['vite']) && $build !== null => $static('Vite', 'dist'),
            default => null,
        };
    }

    /**
     * Describe a Python web app (Django, Flask or FastAPI), or null.
     *
     * @param  RepositorySnapshot  $files
     * @param  string  $folder
     * @return DetectedApp|null
     */
    private function python(RepositorySnapshot $files, string $folder): ?DetectedApp
    {
        $at = fn (string $file): string => ltrim($folder.'/'.$file, '/');
        $requirements = strtolower((string) $files->read($at('requirements.txt')).(string) $files->read($at('pyproject.toml')).(string) $files->read($at('Pipfile')));
        if ($requirements === '') {
            return null;
        }
        $python = $this->version((string) $files->read($at('.python-version')), 2);
        if (str_contains($requirements, 'django')) {
            preg_match("/DJANGO_SETTINGS_MODULE['\"]?\s*,\s*['\"]([\w.]+)\.settings/", (string) $files->read($at('manage.py')), $settings);
            $project = $settings[1] ?? 'config';
            $database = str_contains($requirements, 'psycopg') ? 'pgsql' : (str_contains($requirements, 'mysqlclient') || str_contains($requirements, 'pymysql') ? 'mysql' : 'sqlite');

            $celery = str_contains($requirements, 'celery');

            return new DetectedApp($folder, 'django', 'Django', 'python', $python, database: $database, queue: $celery, redis: str_contains($requirements, 'redis'),
                buildCommand: '.venv/bin/python manage.py collectstatic --noinput', startCommand: ".venv/bin/gunicorn {$project}.wsgi:application --bind 127.0.0.1:\$PORT", port: 8000,
                notes: str_contains($requirements, 'gunicorn') ? [] : [__('Add gunicorn to requirements.txt; it serves the app.')],
                workerCommand: $celery ? ".venv/bin/celery -A {$project} worker" : null);
        }
        if (str_contains($requirements, 'fastapi')) {
            return new DetectedApp($folder, 'fastapi', 'FastAPI', 'python', $python, startCommand: '.venv/bin/uvicorn main:app --host 127.0.0.1 --port $PORT', port: 8000);
        }
        if (str_contains($requirements, 'flask')) {
            return new DetectedApp($folder, 'flask', 'Flask', 'python', $python, startCommand: '.venv/bin/gunicorn app:app --bind 127.0.0.1:$PORT', port: 8000,
                notes: str_contains($requirements, 'gunicorn') ? [] : [__('Add gunicorn to requirements.txt; it serves the app.')]);
        }

        return null;
    }

    /**
     * The package manager a JavaScript app's lock file says it uses.
     *
     * @param  RepositorySnapshot  $files
     * @param  string  $folder
     * @return string
     */
    private function packageManager(RepositorySnapshot $files, string $folder): string
    {
        $at = fn (string $file): string => ltrim($folder.'/'.$file, '/');

        return match (true) {
            $files->has($at('pnpm-lock.yaml')) => 'pnpm',
            $files->has($at('yarn.lock')) => 'yarn',
            $files->has($at('bun.lock')) || $files->has($at('bun.lockb')) => 'bun',
            default => 'npm',
        };
    }

    /**
     * Get the first version in a constraint, to the given number of parts: "^8.3" → 8.3 (two) or 8 (one).
     *
     * @param  string  $constraint
     * @param  int  $parts
     * @return string|null
     */
    private function version(string $constraint, int $parts): ?string
    {
        if (preg_match('/(\d+)(?:\.(\d+))?/', $constraint, $match) !== 1) {
            return null;
        }

        return $parts === 1 || ! isset($match[2]) ? $match[1] : $match[1].'.'.$match[2];
    }

    /**
     * Read KEY=value lines from an example .env file (comments and quotes ignored).
     *
     * @param  string  $contents
     * @return array<string, string>
     */
    private function dotenv(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $match) === 1) {
                $values[$match[1]] = trim($match[2], " \t\"'");
            }
        }

        return $values;
    }

    /**
     * Get the first port a Dockerfile exposes, if any.
     *
     * @param  string  $dockerfile
     * @return int|null
     */
    private function exposedPort(string $dockerfile): ?int
    {
        return preg_match('/^\s*EXPOSE\s+(\d+)/mi', $dockerfile, $match) === 1 ? (int) $match[1] : null;
    }
}
