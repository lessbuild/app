<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Delegate scheduled work to enabled product modules.
     *
     * Define the application's command schedule.
     *
     * @param  Schedule  $schedule  Application scheduler shared by product modules.
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        foreach (config('platform.schedulers', []) as $product => $scheduler) {
            if (! config("platform.products.{$product}.enabled", false)) {
                continue;
            }

            app($scheduler)->register($schedule);
        }
    }

    /**
     * Load command classes and console routes into Artisan discovery.
     *
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
