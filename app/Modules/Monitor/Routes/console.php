<?php

use App\Modules\Monitor\Services\DeliverAlertNotification;
use App\Modules\Monitor\Services\EvaluateAlertRules;
use App\Modules\Monitor\Services\PruneTelemetryData;
use App\Modules\Monitor\Services\ScheduleMonitorChecks;
use App\Modules\Monitor\Services\Telemetry\TelemetryQueue;
use App\Modules\Monitor\Services\WakeSnoozedIssues;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('telemetry:recover {--limit=100 : Maximum missing jobs to recover (1-1000)}', function (TelemetryQueue $queue): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }

    $this->info('Recovered '.$queue->recover($limit).' ingestion deliveries.');

    return 0;
})->purpose('Recover due ingestion deliveries whose durable queue jobs are missing');

Schedule::command('telemetry:recover')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('telemetry:prune {--dry-run : Report records without deleting them} {--workspace= : Restrict pruning to a workspace ID}', function (PruneTelemetryData $pruner): int {
    $workspace = $this->option('workspace');
    $workspaceId = $workspace === null ? null : filter_var($workspace, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($workspace !== null && $workspaceId === false) {
        $this->error('The workspace must be a positive integer.');

        return 2;
    }

    $dryRun = (bool) $this->option('dry-run');
    $summary = $pruner->prune($dryRun, workspaceId: $workspaceId === false ? null : $workspaceId);
    $verb = $dryRun ? 'would prune' : 'pruned';
    $this->info(sprintf(
        'Retention: %s %d workspace(s), %d event(s), %d identity record(s), %d receipt(s), and %d payload(s).',
        $verb,
        $summary['workspaces'],
        $summary['events'],
        $summary['identities'],
        $summary['receipts'],
        $summary['payloads'],
    ));

    return 0;
})->purpose('Prune telemetry and completed delivery records beyond each workspace plan retention window');

Schedule::command('telemetry:prune')->dailyAt('02:30')->withoutOverlapping(30)->onOneServer();

Artisan::command('issues:wake {--limit=100 : Maximum due snoozes to reopen (1-1000)}', function (WakeSnoozedIssues $issues): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }

    $this->info('Reopened '.$issues->wake($limit).' snoozed issues.');

    return 0;
})->purpose('Reopen issues whose snooze deadline has passed');

Schedule::command('issues:wake')->everyMinute()->withoutOverlapping(5)->onOneServer();

Schedule::command('issues:send-digest')->dailyAt('08:00')->withoutOverlapping(60)->onOneServer();

Schedule::command('usage:send-alerts')->hourly()->withoutOverlapping(10)->onOneServer();

Artisan::command('alerts:evaluate {--limit=100 : Maximum due rules to evaluate (1-1000)}', function (EvaluateAlertRules $rules): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }

    $this->info('Evaluated '.$rules->evaluate($limit).' alert rules.');

    return 0;
})->purpose('Evaluate telemetry thresholds and open or recover incidents');

Schedule::command('alerts:evaluate')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('alerts:recover {--limit=100 : Maximum due deliveries to recover (1-1000)}', function (DeliverAlertNotification $deliveries): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }
    $this->info('Recovered '.$deliveries->recover($limit).' alert deliveries.');

    return 0;
})->purpose('Recover alert deliveries whose durable jobs are missing');

Schedule::command('alerts:recover')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('monitors:check {--limit=100 : Maximum checks to schedule (1-1000)}', function (ScheduleMonitorChecks $checks): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }
    $this->info('Scheduled or evaluated '.$checks->schedule($limit).' monitors.');

    return 0;
})->purpose('Recover interrupted checks, schedule network probes and evaluate heartbeat deadlines');

Schedule::command('monitors:check')->everyMinute()->withoutOverlapping(5)->onOneServer();
