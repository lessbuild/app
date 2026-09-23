<?php

namespace Tests\Modules\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

trait RefreshAnalyticsDatabase
{
    use RefreshDatabase;

    protected function refreshDatabase(): void
    {
        DB::setDefaultConnection('analytics');

        foreach ([
            'analytics' => app_path('Modules/Analytics/Database/Migrations'),
            'core' => app_path('Core/Database/Migrations'),
        ] as $connection => $path) {
            $this->artisan('migrate:fresh', [
                '--database' => $connection,
                '--path' => $path,
                '--realpath' => true,
                '--force' => true,
            ])->assertSuccessful();
        }
    }
}
