<?php

declare(strict_types=1);

use App\Actions\Analytics\DispatchPendingBatches;
use App\Actions\Analytics\PruneAnalyticsData;
use App\Actions\Billing\ApplyEndedSelections;
use App\Actions\Billing\ReportUsage;
use App\Actions\Notifications\WarnAboutExpiringTokens;
use App\Actions\Telemetry\PruneTelemetryData;
use App\Actions\Telemetry\WakeSnoozedIssues;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\MonitorScheduler;
use App\Services\Telemetry\TelemetryQueue;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prunable models (sign-in history and friends) drop rows past their retention.
Schedule::command('model:prune')->daily();

Artisan::command('api-tokens:warn-expiring', function (WarnAboutExpiringTokens $warn): void {
    $this->info(trans_choice('Warned :count token owner.|Warned :count token owners.', $count = $warn->handle(), ['count' => $count]));
})->purpose('Tell people about their API tokens that expire within a week');
Schedule::command('api-tokens:warn-expiring')->dailyAt('09:00');

Artisan::command('billing:apply-ended', function (ApplyEndedSelections $apply): void {
    $this->info("Ended {$apply->handle()} plan selections.");
})->purpose('Move services whose paid period has ended to their free tier');
Artisan::command('billing:report-usage', function (ReportUsage $report): void {
    $this->info("Reported {$report->handle()} usage buckets.");
})->purpose('Send new metered usage to Stripe');
Schedule::command('billing:apply-ended')->hourly();
Schedule::command('billing:report-usage')->hourlyAt(5);

Artisan::command('analytics:dispatch-pending {--limit=500}', function (DispatchPendingBatches $dispatch): void {
    $this->info("Dispatched {$dispatch->handle((int) $this->option('limit'))} pending batches.");
})->purpose('Queue analytics batches that are waiting or failed');
Artisan::command('analytics:prune {--days= : Override event and visit retention days}', function (PruneAnalyticsData $prune): void {
    $days = $this->option('days');
    $counts = $prune->handle(is_numeric($days) ? (int) $days : null);
    $this->info("Pruned {$counts['events']} events, {$counts['visits']} visits, {$counts['batches']} batches, {$counts['aggregates']} aggregates and {$counts['exports']} exports.");
})->purpose('Remove analytics data past its retention');
Schedule::command('analytics:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('analytics:prune')->daily()->withoutOverlapping();

Artisan::command('monitors:check {--limit=100 : Maximum checks to schedule (1-1000)}', function (MonitorScheduler $checks): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }
    $this->info('Scheduled or evaluated '.$checks->schedule($limit).' monitors.');

    return 0;
})->purpose('Recover interrupted checks, schedule network probes and evaluate heartbeat and queue deadlines');
Artisan::command('alerts:recover {--limit=100 : Maximum due deliveries to recover (1-1000)}', function (AlertDeliveryRunner $deliveries): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }
    $this->info('Recovered '.$deliveries->recover($limit).' alert deliveries.');

    return 0;
})->purpose('Recover alert deliveries whose queued jobs are missing');
Schedule::command('monitors:check')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('alerts:recover')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('telemetry:recover {--limit=100 : Maximum missing jobs to recover (1-1000)}', function (TelemetryQueue $queue): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }
    $this->info('Recovered '.$queue->recover($limit).' ingestion deliveries.');

    return 0;
})->purpose('Requeue accepted telemetry deliveries whose jobs went missing');
Artisan::command('telemetry:prune {--dry-run : Report what would be deleted} {--account= : Only this account}', function (PruneTelemetryData $prune): void {
    $account = $this->option('account');
    $summary = $prune->handle((bool) $this->option('dry-run'), accountId: is_string($account) ? $account : null);
    $this->info(sprintf('Retention: %s %d account(s), %d event(s), %d identity record(s), %d receipt(s) and %d payload(s).',
        $summary['dry_run'] ? 'would prune' : 'pruned', $summary['accounts'], $summary['events'], $summary['identities'], $summary['receipts'], $summary['payloads']));
})->purpose('Delete telemetry past each account\'s Monitoring retention');
Artisan::command('issues:wake {--limit=100 : Maximum snoozed issues to reopen (1-1000)}', function (WakeSnoozedIssues $wake): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }
    $this->info('Reopened '.$wake->handle($limit).' snoozed issues.');

    return 0;
})->purpose('Reopen issues whose snooze has ended');
Schedule::command('telemetry:recover')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('telemetry:prune')->dailyAt('02:30')->withoutOverlapping(30)->onOneServer();
Schedule::command('issues:wake')->everyMinute()->withoutOverlapping(5)->onOneServer();
