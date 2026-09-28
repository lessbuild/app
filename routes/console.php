<?php

declare(strict_types=1);

use App\Actions\Analytics\DispatchPendingBatches;
use App\Actions\Analytics\PruneAnalyticsData;
use App\Actions\Billing\ApplyEndedSelections;
use App\Actions\Billing\ReportUsage;
use App\Actions\Deploy\FinishBuild;
use App\Actions\Infrastructure\QueueWebsiteBackup;
use App\Actions\Infrastructure\RemoveDatabaseUser;
use App\Actions\Infrastructure\RequestDatabaseInspection;
use App\Actions\Notifications\WarnAboutExpiringTokens;
use App\Actions\Telemetry\PruneTelemetryData;
use App\Actions\Telemetry\WakeSnoozedIssues;
use App\Jobs\Infrastructure\CollectServerMetrics;
use App\Models\AccessRequest;
use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\DatabaseUser;
use App\Models\Environment;
use App\Models\Preview;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\ServerTerminalFrame;
use App\Models\ServerTerminalSession;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackupSchedule;
use App\Models\WebsiteDomain;
use App\Services\Admin\PlatformAdmins;
use App\Services\Admin\SystemHealth;
use App\Services\Billing\Entitlements;
use App\Services\Deploy\Automation;
use App\Services\Deploy\Configuration\ConfigurationOperations;
use App\Services\Deploy\DeploymentObserver;
use App\Services\Deploy\Deployments;
use App\Services\Deploy\Hibernation;
use App\Services\Deploy\Previews;
use App\Services\Infrastructure\ProviderHealthMonitor;
use App\Services\Infrastructure\ServerPricing;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\AlertRuleEvaluator;
use App\Services\Monitoring\MonitorScheduler;
use App\Services\Telemetry\IssueDigest;
use App\Services\Telemetry\TelemetryQueue;
use App\Services\Telemetry\UsageAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

Artisan::command('terminals:expire', function (): int {
    $now = now();
    $stale = ServerTerminalSession::query()->whereIn('status', ServerTerminalSession::ACTIVE)->where(fn ($query) => $query
        ->where('expires_at', '<=', $now)->orWhere('idle_expires_at', '<=', $now)
        ->orWhere(fn ($query) => $query->where('status', 'connecting')->where('created_at', '<=', $now->copy()->subMinutes(2)))
        ->orWhere(fn ($query) => $query->where('status', 'connected')->where('broker_seen_at', '<=', $now->copy()->subMinute())))->get();
    foreach ($stale as $session) {
        [$status, $reason] = match (true) {
            $session->hasExpired() => ['expired', 'expired'],
            $session->status === 'connecting' => ['failed', 'no terminal worker is running'],
            default => ['failed', 'the terminal worker stopped'],
        };
        $session->forceFill(['status' => $status, 'close_reason' => $reason, 'closed_at' => $now])->save();
    }
    $pruned = ServerTerminalFrame::query()->where(fn ($query) => $query->where('created_at', '<=', $now->copy()->subMinutes(10))
        ->orWhereIn('server_terminal_session_id', ServerTerminalSession::query()->whereNotIn('status', ServerTerminalSession::ACTIVE)->select('id')))->delete();
    $this->info("Closed {$stale->count()} terminals; removed {$pruned} frames.");

    return 0;
})->purpose('Close expired or abandoned troubleshooting terminals and remove their leftover frames');
Schedule::command('terminals:expire')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('servers:sync-costs', function (ServerPricing $pricing): int {
    $priced = $pricing->refresh(Server::query()->whereNotNull('provider_id')->with('provider')->get());
    $this->info("Priced {$priced} servers.");

    return 0;
})->purpose('Record what each cloud server costs a month from its provider’s current size catalog');
Schedule::command('servers:sync-costs')->dailyAt('05:10')->withoutOverlapping(60)->onOneServer();

Artisan::command('builds:reap', function (FinishBuild $finish): int {
    $stale = Build::query()->whereIn('status', [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING])
        ->where('last_heartbeat_at', '<', now()->subMinutes(max(1, (int) config('deploy.deployment_stale_minutes'))))->get();
    $stale->each(fn (Build $build) => $finish->handle($build, Build::STATUS_FAILED, 'The deployment stopped reporting from the server.'));
    $this->info("Failed {$stale->count()} stalled deploys.");

    return 0;
})->purpose('Fail deploys whose server stopped reporting progress');
Schedule::command('builds:reap')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('builds:observe', function (DeploymentObserver $observer): int {
    $builds = Build::query()->with(['website', 'environment'])->where('observation_status', 'observing')->get();
    $failed = $builds->filter(fn (Build $build): bool => $observer->check($build) === 'failed')->count();
    $this->info("Checked {$builds->count()} deploys under observation; {$failed} failed.");

    return 0;
})->purpose('Check the health of websites deployed recently, rolling back failures where environments ask for it');
Schedule::command('builds:observe')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('builds:release-pending', function (Deployments $deployments): int {
    $released = 0;
    Repository::query()->where('webhook_pending', true)->with(['environment', 'website.server', 'provider'])->each(function (Repository $repository) use ($deployments, &$released): void {
        if (! $repository->isDeploymentReady() || $deployments->blockReason($repository) !== null) {
            return;
        }
        $build = $deployments->queue($repository, ['trigger_source' => 'webhook', 'revision' => $repository->webhook_pending_revision, 'commit_message' => $repository->webhook_pending_commit_message]);
        if ($build !== null) {
            $repository->forceFill(['webhook_pending' => false, 'webhook_pending_revision' => null, 'webhook_pending_commit_message' => null])->save();
            $released++;
        }
    });
    $this->info("Started {$released} waiting push deploys.");

    return 0;
})->purpose('Deploy pushes that waited for a deployment window, an unlock or a running deploy');
Schedule::command('builds:release-pending')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('configuration:dispatch', function (ConfigurationOperations $operations): int {
    $delivered = 0;
    ConfigurationOperation::query()->whereIn('status', ['pending', 'blocked'])->orderBy('id')->each(function (ConfigurationOperation $operation) use ($operations, &$delivered): void {
        $delivered += (int) ($operations->deliver($operation)->status !== 'blocked');
    });
    ConfigurationApplication::query()->whereNotIn('status', ['succeeded', 'remote_failed', 'locally_applied'])->orderBy('id')->each(fn (ConfigurationApplication $application) => $operations->refresh($application));
    $this->info("Started {$delivered} configuration deploys.");

    return 0;
})->purpose('Start configuration deploys whose gates have cleared, and bring configuration results up to date');
Schedule::command('configuration:dispatch')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('previews:expire', function (Previews $previews): int {
    $expired = 0;
    Preview::query()->with('sourceRepository')->where('status', '!=', Preview::STATUS_CLOSED)->orderBy('id')->each(function (Preview $preview) use ($previews, &$expired): void {
        if ($preview->expiresAt()->isPast()) {
            $previews->close($preview);
            $expired++;
        }
    });
    // A cleanup whose worker died would otherwise wait forever; failing it lets someone retry.
    $stalled = Preview::query()->whereIn('cleanup_status', [Preview::CLEANUP_QUEUED, Preview::CLEANUP_RUNNING])->where('updated_at', '<', now()->subMinutes(30))
        ->update(['cleanup_status' => Preview::CLEANUP_FAILED, 'cleanup_error' => 'The cleanup stopped before it finished.']);
    $this->info("Closed {$expired} expired previews; {$stalled} stalled cleanups can be retried.");

    return 0;
})->purpose('Close previews past their lifetime, and fail preview cleanups that stopped');
Schedule::command('previews:expire')->hourly()->withoutOverlapping(30)->onOneServer();

Artisan::command('automation:dispatch', function (Automation $automation): int {
    $ran = $automation->runDue(now());
    $this->info("Ran {$ran['deploys']} scheduled deploys, {$ran['scaling']} scaling schedules and {$ran['tasks']} scheduled tasks.");

    return 0;
})->purpose('Run the scheduled deploys, scaling schedules and scheduled tasks due this minute');
Schedule::command('automation:dispatch')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('environments:hibernate', function (Hibernation $hibernation): int {
    $hibernating = 0;
    Environment::query()->with('project.account')->whereNotNull('hibernate_after_minutes')->whereNull('hibernated_at')->orderBy('id')
        ->each(function (Environment $environment) use ($hibernation, &$hibernating): void {
            $hibernating += (int) ($hibernation->evaluate($environment) === 'hibernating');
        });
    $this->info("Hibernating {$hibernating} idle environments.");

    return 0;
})->purpose('Hibernate environments that have had no requests or deploys for their idle time');
Schedule::command('environments:hibernate')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

Artisan::command('environments:wake', function (Hibernation $hibernation): int {
    $waking = 0;
    Environment::query()->whereNotNull('hibernated_at')->orderBy('id')->each(function (Environment $environment) use ($hibernation, &$waking): void {
        $waking += (int) $hibernation->wakeIfRequested($environment);
    });
    $this->info("Waking {$waking} hibernated environments.");

    return 0;
})->purpose('Wake hibernated environments whose websites have had a request');
Schedule::command('environments:wake')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('platform:admin {email? : The person\'s email} {--grant} {--revoke} {--allow-last : Allow revoking the last admin} {--list} {--import-allowlist : Grant everyone in PLATFORM_ADMIN_EMAILS}', function (PlatformAdmins $admins): int {
    if ($this->option('list')) {
        $this->table(['Email', 'Granted', 'Second factor'], User::query()->where('is_platform_admin', true)->orderBy('email')->get()
            ->map(fn (User $user): array => [$user->email, (string) $user->platform_admin_granted_at, $user->hasSecondFactor() ? 'yes' : 'no'])->all());

        return 0;
    }
    if ($this->option('import-allowlist')) {
        foreach ((array) config('platform.admin_emails') as $email) {
            $user = User::query()->where('email', strtolower(trim((string) $email)))->first();
            $user === null ? $this->warn("No user with {$email}; skipped.") : $this->line(($admins->grant($user, null, 'cli') ? 'Granted ' : 'Already an admin: ').$email);
        }

        return 0;
    }
    $email = strtolower(trim(is_string($this->argument('email')) ? $this->argument('email') : ''));
    if ($email === '' || $this->option('grant') === $this->option('revoke')) {
        $this->error('Give an email with exactly one of --grant or --revoke, or use --list or --import-allowlist.');

        return 2;
    }
    $user = User::query()->where('email', $email)->first();
    if ($user === null) {
        $this->error("No user with {$email}.");

        return 1;
    }
    try {
        $changed = $this->option('grant') ? $admins->grant($user, null, 'cli') : $admins->revoke($user, null, 'cli', (bool) $this->option('allow-last'));
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage().' Use --allow-last to override.');

        return 1;
    }
    $this->info($changed ? "Updated {$email}." : "No change for {$email}.");
    if ($this->option('grant') && ! $user->hasSecondFactor()) {
        $this->warn('They have no authenticator app or passkey yet; /admin stays closed until they add one.');
    }

    return 0;
})->purpose('Grant, revoke or list platform administrators (admins also need an authenticator app or passkey)');

Artisan::command('platform:heartbeat', function (): int {
    Cache::forever(SystemHealth::HEARTBEAT_KEY, now()->getTimestamp());

    return 0;
})->purpose('Record that the scheduler is running, for the admin health page');
Schedule::command('platform:heartbeat')->everyMinute();

Artisan::command('access-requests:prune', function (): int {
    $deleted = AccessRequest::query()->whereIn('status', ['accepted', 'declined'])
        ->where('updated_at', '<', now()->subDays((int) config('platform.access_request_retention_days')))->delete();
    $this->info("Deleted {$deleted} closed access requests.");

    return 0;
})->purpose('Delete access requests that were accepted or declined long ago');
Schedule::command('access-requests:prune')->dailyAt('03:40')->withoutOverlapping(30)->onOneServer();

Artisan::command('notifications:prune', function (): int {
    $deleted = DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', now()->subDays((int) config('platform.read_notification_retention_days')))->delete();
    $this->info("Deleted {$deleted} old read notifications.");

    return 0;
})->purpose('Delete notifications read long ago');
Schedule::command('notifications:prune')->dailyAt('03:50')->withoutOverlapping(30)->onOneServer();

// For when an address can't receive our email (or mail isn't set up yet): an operator vouches for it instead.
Artisan::command('users:verify {email : The person\'s email}', function (): int {
    $email = strtolower(trim(is_string($this->argument('email')) ? $this->argument('email') : ''));
    $user = User::query()->where('email', $email)->first();
    if ($user === null) {
        $this->error("No user with {$email}.");

        return 1;
    }
    if ($user->hasVerifiedEmail()) {
        $this->line("{$email} is already verified.");

        return 0;
    }
    $user->markEmailAsVerified();
    event(new Verified($user));
    $this->info("Verified {$email}.");

    return 0;
})->purpose('Mark a person\'s email address as verified');
