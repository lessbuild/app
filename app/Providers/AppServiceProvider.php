<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Platform-wide service bindings belong here. Product bindings are
        // registered by their owning module providers.
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands(
            (bool) config('lessbuild.prohibit_destructive_database_commands'),
        );
    }
}
