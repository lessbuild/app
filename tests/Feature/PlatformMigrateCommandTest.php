<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformMigrateCommandTest extends TestCase
{
    use RefreshDatabase;

    private ?string $migrationPath = null;

    protected function tearDown(): void
    {
        if ($this->migrationPath !== null) {
            File::deleteDirectory($this->migrationPath);
        }

        parent::tearDown();
    }

    public function test_module_migration_runs_unqualified_schema_and_queries_on_selected_connection(): void
    {
        $this->migrationPath = $this->migrationDirectory('2026_09_23_000000_create_connection_scope_probe.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('platform_migrate_probe', function (Blueprint $table): void {
                        $table->id();
                        $table->string('value');
                    });

                    DB::table('platform_migrate_probe')->insert(['value' => 'core']);
                }

                public function down(): void
                {
                    Schema::dropIfExists('platform_migrate_probe');
                }
            };
            PHP);
        config(['platform.migrations.core.path' => $this->migrationPath]);

        $previousConnection = DB::getDefaultConnection();
        $exitCode = Artisan::call('platform:migrate', ['module' => 'core']);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue(Schema::connection('core')->hasTable('platform_migrate_probe'));
        $this->assertFalse(Schema::connection('deployer')->hasTable('platform_migrate_probe'));
        $this->assertSame('core', DB::connection('core')->table('platform_migrate_probe')->value('value'));
        $this->assertSame(1, DB::connection('core')->table('migrations')
            ->where('migration', '2026_09_23_000000_create_connection_scope_probe')
            ->count());
        $this->assertSame(0, DB::connection('deployer')->table('migrations')
            ->where('migration', '2026_09_23_000000_create_connection_scope_probe')
            ->count());
        $this->assertSame($previousConnection, DB::getDefaultConnection());
    }

    public function test_module_migration_restores_default_connection_after_a_failure(): void
    {
        $this->migrationPath = $this->migrationDirectory('2026_09_23_000001_fail_connection_scope_probe.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;

            return new class extends Migration
            {
                public function up(): void
                {
                    throw new RuntimeException('Expected migration failure.');
                }
            };
            PHP);
        config(['platform.migrations.core.path' => $this->migrationPath]);

        $previousConnection = DB::getDefaultConnection();
        $failure = null;

        try {
            Artisan::call('platform:migrate', ['module' => 'core']);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertSame('Expected migration failure.', $failure->getMessage());
        $this->assertSame($previousConnection, DB::getDefaultConnection());
    }

    private function migrationDirectory(string $filename, string $contents): string
    {
        $directory = storage_path('framework/testing/migrations/'.Str::uuid());
        File::ensureDirectoryExists($directory);
        File::put($directory.'/'.$filename, $contents);

        return $directory;
    }
}
