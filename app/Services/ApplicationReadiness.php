<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

class ApplicationReadiness
{
    public function __construct(private readonly Migrator $migrator) {}

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
