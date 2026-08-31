<?php

namespace Tests\Feature;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DatabaseCommandSafetyTest extends TestCase
{
    private string $temporaryDirectory;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = storage_path('framework/testing/database-safety-'.Str::uuid());
        $this->databasePath = $this->temporaryDirectory.'/database.sqlite';
        File::ensureDirectoryExists($this->temporaryDirectory);
        File::put($this->databasePath, '');
        $database = new PDO('sqlite:'.$this->databasePath);
        $database->exec('CREATE TABLE safety_marker (value TEXT NOT NULL)');
        $database->exec("INSERT INTO safety_marker (value) VALUES ('preserved')");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_explicit_guard_blocks_destructive_commands_even_with_a_testing_environment_label(): void
    {
        foreach (['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'] as $command) {
            $process = $this->artisanProcess($command);
            $process->run();

            $this->assertFalse($process->isSuccessful(), $command);
            $this->assertStringContainsString(
                'This command is prohibited from running in this environment.',
                $process->getOutput().$process->getErrorOutput(),
                $command,
            );
        }
        $database = new PDO('sqlite:'.$this->databasePath);
        $this->assertSame('preserved', $database->query('SELECT value FROM safety_marker')->fetchColumn());
    }

    public function test_phpunit_environment_explicitly_allows_its_isolated_database_refreshes(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertFalse(config('lessbuild.prohibit_destructive_database_commands'));
        foreach ([
            WipeCommand::class,
            FreshCommand::class,
            RefreshCommand::class,
            ResetCommand::class,
            RollbackCommand::class,
        ] as $command) {
            $property = (new ReflectionClass($command))->getProperty('prohibitedFromRunning');
            $this->assertFalse($property->getValue(), $command);
        }
    }

    private function artisanProcess(string $command): Process
    {
        return new Process(
            [PHP_BINARY, 'artisan', $command, '--force', '--no-interaction', '--env=testing'],
            base_path(),
            [
                'APP_CONFIG_CACHE' => $this->temporaryDirectory.'/config.php',
                'APP_ENV' => 'testing',
                'CACHE_DRIVER' => 'array',
                'DATABASE_PROHIBIT_DESTRUCTIVE_COMMANDS' => 'true',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->databasePath,
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
                'TELESCOPE_ENABLED' => 'false',
            ],
        );
    }
}
