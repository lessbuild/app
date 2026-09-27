<?php

declare(strict_types=1);

use App\Actions\Analytics\DispatchPendingBatches;
use App\Actions\Analytics\PruneAnalyticsData;
use App\Actions\Billing\ApplyEndedSelections;
use App\Actions\Billing\ReportUsage;
use App\Actions\Infrastructure\QueueWebsiteBackup;
use App\Actions\Infrastructure\RemoveDatabaseUser;
use App\Actions\Infrastructure\RequestDatabaseInspection;
use App\Actions\Notifications\WarnAboutExpiringTokens;
use App\Actions\Telemetry\PruneTelemetryData;
use App\Actions\Telemetry\WakeSnoozedIssues;
use App\Jobs\Infrastructure\CollectServerMetrics;
use App\Models\DatabaseUser;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\Website;
use App\Models\WebsiteBackupSchedule;
use App\Models\WebsiteDomain;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\ProviderHealthMonitor;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\AlertRuleEvaluator;
use App\Services\Monitoring\MonitorScheduler;
use App\Services\Telemetry\IssueDigest;
use App\Services\Telemetry\TelemetryQueue;
use App\Services\Telemetry\UsageAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

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

Artisan::command('alerts:evaluate {--limit=100 : Maximum due rules to evaluate (1-1000)}', function (AlertRuleEvaluator $rules): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 1000.');

        return 2;
    }
    $this->info('Evaluated '.$rules->evaluate($limit).' alert rules.');

    return 0;
})->purpose('Evaluate telemetry alert rules and open or recover incidents');
Schedule::command('alerts:evaluate')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('usage:send-alerts {--account= : Only this account} {--at= : Evaluate usage at this UTC time}', function (UsageAlerts $alerts): int {
    try {
        $at = is_string($this->option('at')) ? CarbonImmutable::parse($this->option('at'), 'UTC') : null;
    } catch (Throwable) {
        $this->error('The at timestamp is invalid.');

        return 2;
    }
    $totals = $alerts->send($at, is_string($this->option('account')) ? $this->option('account') : null);
    $this->info(sprintf('Usage alerts: %d sent, %d skipped, %d failed.', $totals['sent'], $totals['skipped'], $totals['failed']));

    return $totals['failed'] === 0 ? 0 : 1;
})->purpose('Email account owners when Monitoring usage reaches 80% and 100% of the monthly allowance');
Schedule::command('usage:send-alerts')->hourly()->withoutOverlapping(10)->onOneServer();

Artisan::command('issues:send-digest {--account= : Only this account} {--from= : Inclusive UTC start} {--until= : Exclusive UTC end}', function (IssueDigest $digest): int {
    try {
        $until = is_string($this->option('until')) ? CarbonImmutable::parse($this->option('until'), 'UTC') : CarbonImmutable::now('UTC')->startOfMinute();
        $from = is_string($this->option('from')) ? CarbonImmutable::parse($this->option('from'), 'UTC') : $until->subDay();
    } catch (Throwable) {
        $this->error('The from or until timestamp is invalid.');

        return 2;
    }
    if ($from->greaterThanOrEqualTo($until)) {
        $this->error('The from timestamp must be before the until timestamp.');

        return 2;
    }
    $totals = $digest->send($from, $until, is_string($this->option('account')) ? $this->option('account') : null);
    $this->info(sprintf('Issue digests: %d sent, %d skipped, %d failed.', $totals['sent'], $totals['skipped'], $totals['failed']));

    return $totals['failed'] === 0 ? 0 : 1;
})->purpose('Send the daily issue digest');
Schedule::command('issues:send-digest')->dailyAt('08:00')->withoutOverlapping(60)->onOneServer();

Artisan::command('providers:check {--provider=* : Only these provider IDs}', function (ProviderHealthMonitor $monitor): int {
    $ids = array_values(array_filter(array_map('intval', (array) $this->option('provider')), fn (int $id): bool => $id > 0));
    $query = Provider::query()->where('connection_monitoring_enabled', true)->orderByRaw('connection_checked_at IS NOT NULL')->orderBy('connection_checked_at')->orderBy('id');
    if ($ids !== []) {
        $query->whereKey($ids);
    } else {
        $query->where(function ($query): void {
            $query->whereNull('connection_checked_at');
            foreach (Provider::CHECK_INTERVALS as $minutes) {
                // The command runs every five minutes; a minute's slack keeps a check from slipping a whole interval.
                $query->orWhere(fn ($query) => $query->where('connection_check_interval_minutes', $minutes)->where('connection_checked_at', '<=', now()->subMinutes($minutes - 1)));
            }
        });
    }
    $checked = $failed = $discarded = 0;
    foreach ($query->limit(max(1, (int) config('infrastructure.provider_check_batch_size')))->get() as $provider) {
        $result = $monitor->check($provider, automatic: true);
        if (! $result['recorded']) {
            $discarded++;

            continue;
        }
        $checked++;
        $failed += (int) ! $result['successful'];
    }
    $this->info("Checked {$checked} providers; {$failed} failed; {$discarded} discarded.");

    return 0;
})->purpose('Check provider credentials that are due, and tell their creators when a connection fails or recovers');
Schedule::command('providers:check')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

Artisan::command('servers:collect-metrics', function (): void {
    $count = 0;
    Server::query()->where('provisioning_status', Server::STATUS_ACTIVE)->orderBy('id')->eachById(function (Server $server) use (&$count): void {
        CollectServerMetrics::dispatch($server->id);
        $count++;
    });
    $this->info("Queued metrics for {$count} servers.");
})->purpose('Collect load, CPU, memory, disk and network use from every active server');
Schedule::command('servers:collect-metrics')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();

Artisan::command('servers:prune-commands {--days= : Keep finished commands for this many days}', function (): int {
    $days = filter_var($this->option('days') ?? config('infrastructure.server_command_retention_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($days === false) {
        $this->error('Retention days must be a positive integer.');

        return 2;
    }
    $deleted = ServerCommandExecution::query()->whereIn('status', ServerCommandExecution::FINISHED)->where('created_at', '<', now()->subDays($days))->delete();
    $this->info("Pruned {$deleted} server commands older than {$days} days.");

    return 0;
})->purpose('Delete finished server commands older than the retention period');
Schedule::command('servers:prune-commands')->dailyAt('03:10')->withoutOverlapping(30)->onOneServer();

Artisan::command('domains:check {--limit=100 : Most domains to check}', function (): int {
    $limit = max(1, min(500, (int) $this->option('limit')));
    $domains = WebsiteDomain::query()->with('website.server')->whereHas('website')->orderByRaw('last_checked_at IS NOT NULL')->orderBy('last_checked_at')->limit($limit)->get();
    foreach ($domains as $domain) {
        $addresses = array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, @dns_get_record($domain->hostname, DNS_A | DNS_AAAA) ?: [])));
        $expected = $domain->website->server?->public_ip;
        $dns = $expected !== null && in_array($expected, $addresses, true) ? 'active' : 'pending';
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $domain->hostname, 'SNI_enabled' => true]]);
        try {
            $socket = @stream_socket_client('ssl://'.$domain->hostname.':443', $code, $error, 8, STREAM_CLIENT_CONNECT, $context);
            if (! is_resource($socket)) {
                throw new RuntimeException('TLS connection failed.');
            }
            $params = stream_context_get_params($socket);
            fclose($socket);
            $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '');
            $expires = is_array($certificate) && isset($certificate['validTo_time_t']) ? CarbonImmutable::createFromTimestampUTC((int) $certificate['validTo_time_t']) : throw new RuntimeException('No expiry.');
            $ssl = $expires->isPast() ? 'expired' : ($expires->lessThanOrEqualTo(now()->addDays((int) config('infrastructure.certificate_warning_days'))) ? 'expiring' : 'active');
            $domain->forceFill(['dns_status' => $dns, 'ssl_status' => $ssl, 'certificate_expires_at' => $expires, 'last_checked_at' => now(), 'last_error' => null])->save();
        } catch (Throwable) {
            $domain->forceFill(['dns_status' => $dns, 'ssl_status' => 'error', 'last_checked_at' => now(), 'last_error' => 'TLS verification failed.'])->save();
        }
    }
    $this->info("Checked {$domains->count()} domains.");

    return 0;
})->purpose('Check that website domains point at their server and their TLS certificates are valid');
Schedule::command('domains:check')->hourly()->withoutOverlapping(30)->onOneServer();

Artisan::command('backups:run', function (QueueWebsiteBackup $queue, Entitlements $entitlements): int {
    $now = CarbonImmutable::now('UTC');
    $queued = 0;
    WebsiteBackupSchedule::query()->with(['website.account', 'destination'])->whereHas('website', fn ($query) => $query->where('provisioning_status', Website::STATUS_ACTIVE))
        ->orderBy('id')->eachById(function (WebsiteBackupSchedule $schedule) use ($queue, $entitlements, $now, &$queued): void {
            if (! $schedule->isDue($now) || ! $entitlements->for($schedule->website->account)->has('deploy.backups')) {
                return;
            }
            try {
                $queued += (int) ($queue->handle($schedule->website, $schedule->destination, schedule: $schedule) !== null);
            } catch (ValidationException) {
                // The website stopped being live since the query ran; the next run picks it up.
            }
        });
    $this->info("Queued {$queued} website backups.");

    return 0;
})->purpose('Queue scheduled website backups that are due');
Schedule::command('backups:run')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

Artisan::command('databases:expire-users', function (RemoveDatabaseUser $remove): int {
    $expired = DatabaseUser::query()->whereIn('status', ['active', 'failed'])->whereNotNull('expires_at')->where('expires_at', '<=', now())->get();
    $expired->each(fn (DatabaseUser $user) => $remove->handle($user));
    $this->info("Removing {$expired->count()} expired database users.");

    return 0;
})->purpose('Drop database users whose access has expired');
Schedule::command('databases:expire-users')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();

Artisan::command('databases:inspect', function (RequestDatabaseInspection $inspect, Entitlements $entitlements): int {
    $queued = 0;
    Website::query()->with('account')->where('provisioning_status', Website::STATUS_ACTIVE)->orderBy('id')->eachById(function (Website $website) use ($inspect, $entitlements, &$queued): void {
        if ($entitlements->for($website->account)->has('deploy.resources')) {
            $queued += (int) ($inspect->handle($website) !== null);
        }
    });
    $this->info("Queued {$queued} database inspections.");

    return 0;
})->purpose('Record the size, connections and tables of every live website database');
Schedule::command('databases:inspect')->dailyAt('04:20')->withoutOverlapping(60)->onOneServer();
