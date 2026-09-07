<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

class ApplicationReadiness
{
    /**
     * Bind migration discovery for a read-only application-readiness check.
     *
     * @param  Migrator  $migrator  Compares repository migration files with the migration history.
     */
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * Check that the migration repository exists and every application migration has run.
     *
     * @return bool True only when no migration is pending; unavailable storage or migration errors return false.
     */
    public function isReady(): bool
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return false;
            }

            $availableMigrations = array_keys(
                $this->migrator->getMigrationFiles(database_path('migrations')),
            );
            $completedMigrations = $this->migrator->getRepository()->getRan();

            return array_diff($availableMigrations, $completedMigrations) === [];
        } catch (Throwable) {
            return false;
        }
    }
}
