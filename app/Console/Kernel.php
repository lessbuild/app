<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Schema;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('lessbuild:websites:health')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('lessbuild:providers:health')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('lessbuild:deployments:watchdog')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:servers:metrics')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:previews:expire')->everyTenMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:backups:run')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:deployments:scheduled')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:configuration:process')->everyMinute()
            ->when(fn (): bool => Schema::hasTable('configuration_operations')
                && Schema::hasTable('configuration_operation_receipts')
                && Schema::hasColumn('configuration_operations', 'retry_of_operation_id'))
            ->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:scaling:scheduled')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:tasks:scheduled')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:environments:hibernate')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:environments:wake')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:monitoring:heartbeat')
            ->everyMinute()
            ->when(fn (): bool => filled(config('monitoring.heartbeat_url')))
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('lessbuild:webhooks:prune')->daily()->withoutOverlapping()->runInBackground();
        $schedule->command('lessbuild:commands:prune')->daily()->withoutOverlapping()->runInBackground();
        $schedule->command('lessbuild:notifications:prune')->daily()->withoutOverlapping()->runInBackground();
        $schedule->command('lessbuild:sign-ins:prune')->daily()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:access-requests:prune')->daily()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:server-imports:prune')->everyTenMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:operational-incidents:prune')->daily()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:domains:check')->dailyAt('03:20')->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:database-users:expire')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:load-balancers:check')->everyMinute()->withoutOverlapping()->runInBackground();
    }

    /**
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
