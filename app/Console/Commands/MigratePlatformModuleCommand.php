<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

class MigratePlatformModuleCommand extends Command
{
    protected $signature = 'platform:migrate
                            {module : core, deployer, monitor, or analytics}
                            {--pretend : Show the SQL without applying migrations}
                            {--step : Run migrations so each has an individual batch number}
                            {--force : Allow migrations to run in production}';

    protected $description = 'Run one platform module migration directory against its named database';

    public function handle(Migrator $migrator): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Pass --force to run module migrations in production.');

            return self::FAILURE;
        }

        $module = (string) $this->argument('module');
        $configuration = config("platform.migrations.{$module}");

        if (! is_array($configuration)
            || ! is_string($configuration['connection'] ?? null)
            || ! is_string($configuration['path'] ?? null)) {
            $this->error("Unknown platform module [{$module}].");

            return self::FAILURE;
        }

        $path = $configuration['path'];

        if (! is_dir($path)) {
            $this->error("Migration directory for [{$module}] does not exist: {$path}");

            return self::FAILURE;
        }

        if ($migrator->getMigrationFiles([$path]) === []) {
            $this->components->info("No migrations are defined for [{$module}].");

            return self::SUCCESS;
        }

        $connection = $configuration['connection'];
        $migrator->setOutput($this->output);

        $migrator->usingConnection($connection, function () use ($migrator, $path): void {
            if (! $migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }

            $migrator->run([$path], [
                'pretend' => (bool) $this->option('pretend'),
                'step' => (bool) $this->option('step'),
            ]);
        });

        return self::SUCCESS;
    }
}
