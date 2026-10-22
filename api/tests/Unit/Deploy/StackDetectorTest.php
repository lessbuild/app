<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use App\Data\Deploy\DetectedApp;
use App\Data\Deploy\RepositorySnapshot;
use App\Services\Deploy\StackDetector;
use Tests\TestCase;

final class StackDetectorTest extends TestCase
{
    /**
     * A repository with a Laravel API in api/ and a Nuxt site in web/ (like vacenly.com) is two apps, the API first,
     * each with what it needs and how it builds and starts.
     *
     * @return void
     */
    public function test_a_monorepo_with_an_api_and_a_website(): void
    {
        $apps = $this->detect([
            'api/composer.json' => json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^12.0', 'predis/predis' => '^2.0']]),
            'api/package.json' => json_encode(['scripts' => ['build' => 'vite build'], 'devDependencies' => ['vite' => '^7.0']]),
            'api/package-lock.json' => '{}',
            'api/.env.example' => "APP_NAME=Vacenly\nDB_CONNECTION=mysql\nQUEUE_CONNECTION=database\n",
            'api/app/Jobs/SendDigest.php' => '<?php',
            'api/routes/console.php' => "<?php\nSchedule::command('digest:send')->daily();",
            'web/package.json' => json_encode(['scripts' => ['build' => 'nuxt build'], 'dependencies' => ['nuxt' => '^4.1.0'], 'engines' => ['node' => '>=22']]),
            'web/pnpm-lock.yaml' => '',
            'docs/index.md' => '# Docs',
            '.github/workflows/ci.yml' => '',
        ]);

        $this->assertCount(2, $apps);
        [$api, $web] = $apps;
        $this->assertSame(['api', 'laravel', 'Laravel 12', 'php', '8.4', 'mysql', true, true, true, 'npm run build'], [$api->path, $api->framework, $api->label, $api->runtime, $api->runtimeVersion, $api->database, $api->queue, $api->scheduler, $api->redis, $api->buildCommand]);
        $this->assertSame(['web', 'nuxt', 'Nuxt 4', 'node', '22', 'pnpm run build', 'node .output/server/index.mjs', 3000], [$web->path, $web->framework, $web->label, $web->runtime, $web->runtimeVersion, $web->buildCommand, $web->startCommand, $web->port]);
        $this->assertFalse($web->isBackend());
    }

    /**
     * A Laravel app at the top level with nothing queued or scheduled needs neither; Laravel 11 and later default to
     * SQLite when .env.example doesn't say, and Horizon means Redis and a note about its worker.
     *
     * @return void
     */
    public function test_laravel_at_the_top_level(): void
    {
        [$app] = $this->detect(['composer.json' => json_encode(['require' => ['laravel/framework' => '^11.9']]), 'app/Models/User.php' => '<?php', 'resources/js/app.js' => '']);
        $this->assertSame(['', 'Laravel 11', 'sqlite', false, false, false, null], [$app->path, $app->label, $app->database, $app->queue, $app->scheduler, $app->redis, $app->buildCommand]);

        [$horizon] = $this->detect(['composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0', 'laravel/horizon' => '^5.0']])]);
        $this->assertTrue($horizon->queue && $horizon->redis);
        $this->assertStringContainsString('php artisan horizon', $horizon->notes[0]);
    }

    /**
     * JavaScript, Python and Ruby apps, a static build, and a package that only holds a library.
     *
     * @return void
     */
    public function test_other_stacks(): void
    {
        [$next] = $this->detect(['package.json' => json_encode(['scripts' => ['build' => 'next build', 'start' => 'next start'], 'dependencies' => ['next' => '15.2.0']]), 'yarn.lock' => '']);
        $this->assertSame(['next', 'Next.js 15', 'yarn run start'], [$next->framework, $next->label, $next->startCommand]);

        [$vite] = $this->detect(['package.json' => json_encode(['scripts' => ['build' => 'vite build'], 'devDependencies' => ['vite' => '^7.0']])]);
        $this->assertSame(['static', 'node', 'npx --yes serve@14 --single dist --listen tcp://127.0.0.1:$PORT'], [$vite->framework, $vite->runtime, $vite->startCommand]);

        [$django] = $this->detect([
            'requirements.txt' => "Django==5.1\npsycopg[binary]\ngunicorn\ncelery\nredis\n",
            'manage.py' => "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shop.settings')",
        ]);
        $this->assertSame(['django', 'python', 'pgsql', true, true, '.venv/bin/gunicorn shop.wsgi:application --bind 127.0.0.1:$PORT', []], [$django->framework, $django->runtime, $django->database, $django->queue, $django->redis, $django->startCommand, $django->notes]);

        [$rails] = $this->detect(['Gemfile' => "source 'https://rubygems.org'\ngem 'rails', '~> 8.0'\n"]);
        $this->assertSame(['rails', 'docker'], [$rails->framework, $rails->runtime]);
        $this->assertStringContainsString('Dockerfile', $rails->notes[0]);

        $this->assertSame([], $this->detect(['package.json' => json_encode(['name' => '@acme/utils', 'devDependencies' => ['typescript' => '^5']]), 'src/index.ts' => '']), 'A library isn’t an app.');
        $this->assertSame(['apps/admin', 'apps/shop'], array_map(fn (DetectedApp $app): string => $app->path, $this->detect([
            'apps/shop/package.json' => json_encode(['dependencies' => ['nuxt' => '^4.0']]),
            'apps/admin/package.json' => json_encode(['dependencies' => ['next' => '^15.0']]),
            'packages/ui/package.json' => json_encode(['dependencies' => ['vue' => '^3']]),
        ])), 'Monorepo apps under apps/ count; shared packages don’t.');
    }

    /**
     * Detect the apps in a repository made of the given files (folders are listed the way providers list them).
     *
     * @param  array<string, string|false>  $files
     * @return list<DetectedApp>
     */
    private function detect(array $files): array
    {
        $paths = [];
        foreach (array_keys($files) as $path) {
            $parts = explode('/', $path);
            for ($i = 1; $i < count($parts); $i++) {
                $paths[] = implode('/', array_slice($parts, 0, $i));
            }
            $paths[] = $path;
        }

        return (new StackDetector)->detect(new RepositorySnapshot(array_values(array_unique($paths)), fn (string $path): ?string => isset($files[$path]) ? (string) $files[$path] : null));
    }
}
