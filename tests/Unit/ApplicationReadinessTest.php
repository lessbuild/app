<?php

namespace Tests\Unit;

use App\Services\ApplicationReadiness;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ApplicationReadinessTest extends TestCase
{
    public function test_it_is_ready_when_every_migration_has_run(): void
    {
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('getRan')->once()->andReturn(['2026_01_01_000000_create_users']);

        $migrator = Mockery::mock(Migrator::class);
        $migrator->shouldReceive('repositoryExists')->once()->andReturnTrue();
        $migrator->shouldReceive('getMigrationFiles')->once()->andReturn([
            '2026_01_01_000000_create_users' => '/database/migrations/users.php',
        ]);
        $migrator->shouldReceive('getRepository')->once()->andReturn($repository);

        $this->assertTrue((new ApplicationReadiness($migrator))->isReady());
    }

    public function test_it_is_unavailable_when_a_migration_is_pending(): void
    {
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('getRan')->once()->andReturn([]);

        $migrator = Mockery::mock(Migrator::class);
        $migrator->shouldReceive('repositoryExists')->once()->andReturnTrue();
        $migrator->shouldReceive('getMigrationFiles')->once()->andReturn([
            '2026_01_01_000000_create_users' => '/database/migrations/users.php',
        ]);
        $migrator->shouldReceive('getRepository')->once()->andReturn($repository);

        $this->assertFalse((new ApplicationReadiness($migrator))->isReady());
    }

    public function test_it_is_unavailable_when_the_database_check_fails(): void
    {
        $migrator = Mockery::mock(Migrator::class);
        $migrator->shouldReceive('repositoryExists')->once()->andThrow(new RuntimeException('connection failed'));

        $this->assertFalse((new ApplicationReadiness($migrator))->isReady());
    }
}
