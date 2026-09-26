<?php

namespace App\Modules\Deployer\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

final class DeployerSchedule
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('lessbuild:websites:health')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('lessbuild:providers:health')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('lessbuild:deployments:watchdog')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:servers:metrics')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:previews:expire')->everyTenMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:backups:run')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:deployments:scheduled')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('buildpusher:configuration:process')->everyMinute()
            ->when(fn (): bool => Schema::connection('deployer')->hasTable('configuration_operations')
                && Schema::connection('deployer')->hasTable('configuration_operation_receipts')
                && Schema::connection('deployer')->hasColumn('configuration_operations', 'retry_of_operation_id'))
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
        $schedule->command('buildpusher:deployments:observe')
            ->everyMinute()
            ->when(fn (): bool => Schema::connection('deployer')->hasTable('deployment_observations'))
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('buildpusher:observability:investigations:prune')
            ->daily()
            ->when(fn (): bool => Schema::connection('deployer')->hasTable('observability_investigation_views'))
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('buildpusher:alert-deliveries:reconcile')
            ->everyMinute()
            ->when(fn (): bool => Schema::connection('deployer')->hasTable('alert_outbound_deliveries')
                && Schema::connection('deployer')->hasTable('alert_outbound_delivery_payloads'))
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('buildpusher:troubleshooting:sessions:expire')
            ->everyMinute()
            ->when(fn (): bool => Schema::connection('deployer')->hasTable('server_troubleshooting_sessions'))
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('buildpusher:troubleshooting:frames:prune')
            ->everyMinute()
            ->when(fn (): bool => Schema::connection('deployer')->hasTable('server_troubleshooting_frames'))
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('deployer:billing:reconcile-core-events')
            ->everyFifteenMinutes()
            ->when(fn (): bool => config('billing.plan_authority') !== 'legacy')
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
        $schedule->command('buildpusher:load-balancers:reconcile-removals')
            ->everyFiveMinutes()
            ->when(fn (): bool => Schema::connection('deployer')->hasTable('load_balancers'))
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('buildpusher:databases:reconcile-operations')
            ->everyFiveMinutes()
            ->when(fn (): bool => Schema::connection('deployer')->hasTable('database_operation_runs'))
            ->withoutOverlapping()
            ->runInBackground();
    }
}
