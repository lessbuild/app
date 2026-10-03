<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Data\Admin\HealthCheck;
use App\Data\Admin\QueueState;
use App\Models\AuditEntry;
use App\Models\PlatformAdminEvent;
use App\Models\RepositoryWebhookDelivery;
use App\Models\SignInEvent;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks the platform itself: configuration, the database and its migrations, writable storage, mail and Stripe, the
 * scheduler's heartbeat, and the database queues' backlogs and failed jobs. Details are safe to show and export: they
 * never include secrets or exception messages.
 */
final class SystemHealth
{
    /**
     * The cache key `platform:heartbeat` writes the time to every minute.
     *
     * @var string
     */
    public const HEARTBEAT_KEY = 'platform:heartbeat';

    /**
     * Create a new SystemHealth instance.
     *
     * Runs the platform's health checks.
     *
     * @param  Migrator  $migrator  Knows which migrations have run.
     * @param  PlatformBackups  $backups  Knows when the database was last backed up, and whether off-site.
     * @param  SelfMonitoring  $selfMonitoring  Knows whether the platform watches itself.
     */
    public function __construct(private readonly Migrator $migrator, private readonly PlatformBackups $backups, private readonly SelfMonitoring $selfMonitoring) {}

    /**
     * Run every check.
     *
     * @return list<HealthCheck>
     */
    public function checks(): array
    {
        $production = app()->isProduction();
        $url = (string) config('app.url');
        $validUrl = in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) && is_string(parse_url($url, PHP_URL_HOST));
        $queue = (string) config('queue.default');
        $mailer = (string) config('mail.default');
        $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        $heartbeatAge = is_int($heartbeat) ? now()->getTimestamp() - $heartbeat : null;
        $failed = $this->failedJobCount();

        return [
            new HealthCheck('Application key', 'runtime', filled(config('app.key')), filled(config('app.key')) ? 'Set' : 'Missing'),
            new HealthCheck('Application URL', 'runtime', $validUrl, $validUrl ? $url : 'Needs an http(s) scheme and host'),
            new HealthCheck('Debug mode', 'runtime', ! $production || ! config('app.debug'), $production && config('app.debug') ? 'On in production' : ($production ? 'Off' : 'Environment: '.app()->environment())),
            $this->database(),
            $this->migrations(),
            $this->writable('Storage', storage_path()),
            $this->writable('Bootstrap cache', base_path('bootstrap/cache')),
            new HealthCheck('Queue connection', 'processes', ! $production || $queue !== 'sync', $production && $queue === 'sync' ? 'Jobs run inline in production' : $queue),
            new HealthCheck('Scheduler', 'processes', $heartbeatAge !== null && $heartbeatAge <= 180, $heartbeatAge === null ? 'No heartbeat yet: is `schedule:run` running every minute?' : trans_choice('Last ran :count second ago|Last ran :count seconds ago', $heartbeatAge)),
            new HealthCheck('Failed jobs', 'processes', $failed === 0, $failed === null ? 'Unavailable' : trans_choice(':count failed job|:count failed jobs', $failed)),
            new HealthCheck('Mail', 'connectivity', ! $production || ! in_array($mailer, ['log', 'array'], true), $production && in_array($mailer, ['log', 'array'], true) ? "The {$mailer} mailer sends nothing" : $mailer),
            $this->backups(),
            new HealthCheck('Self-monitoring', 'processes', ! app()->isProduction() || $this->selfMonitoring->settings() !== null, $this->selfMonitoring->settings() !== null ? 'Uptime, the scheduler and exceptions are watched in the operations account' : 'Not set up: run php artisan platform:self-monitor'),
            new HealthCheck('Stripe', 'connectivity', ! $production || (filled(config('services.stripe.secret')) && filled(config('services.stripe.webhook_secret'))), filled(config('services.stripe.secret')) ? 'Keys set' : 'Keys missing: paid plans can’t be bought'),
        ];
    }

    /**
     * Check that the database was backed up in the last day and, in production, copied off-site.
     *
     * @return HealthCheck
     */
    private function backups(): HealthCheck
    {
        $latest = $this->backups->latestSuccessful();
        $fresh = $latest?->created_at !== null && $latest->created_at->gt(now()->subHours(26));
        $offsite = $this->backups->offsiteConfigured();
        $detail = match (true) {
            $latest?->created_at === null => 'No backup yet: run php artisan platform:backup',
            ! $offsite => 'Last backup '.$latest->created_at->diffForHumans().', kept on this server only: set PLATFORM_BACKUP_S3_*',
            ! $latest->isOffsite() => 'Last backup '.$latest->created_at->diffForHumans().' didn’t reach off-site storage',
            default => 'Last backup '.$latest->created_at->diffForHumans().', copied off-site',
        };

        return new HealthCheck('Database backups', 'storage', $fresh && (! app()->isProduction() || ($offsite && $latest->isOffsite())), $detail);
    }

    /**
     * Describe what the platform deletes on a schedule, and after how long.
     *
     * @return list<array{data: string, keeps: string, job: string}>
     */
    public function retention(): array
    {
        return [
            ['data' => __('Sign-in history'), 'keeps' => trans_choice(':count day|:count days', SignInEvent::RETENTION_DAYS), 'job' => 'model:prune'],
            ['data' => __('Account audit log'), 'keeps' => trans_choice(':count day|:count days', AuditEntry::RETENTION_DAYS), 'job' => 'model:prune'],
            ['data' => __('Repository webhook deliveries'), 'keeps' => trans_choice(':count day|:count days', RepositoryWebhookDelivery::RETENTION_DAYS), 'job' => 'model:prune'],
            ['data' => __('Admin trail'), 'keeps' => trans_choice(':count day|:count days', PlatformAdminEvent::RETENTION_DAYS), 'job' => 'model:prune'],
            ['data' => __('Read notifications'), 'keeps' => trans_choice(':count day|:count days', (int) config('platform.read_notification_retention_days')), 'job' => 'notifications:prune'],
            ['data' => __('Closed access requests'), 'keeps' => trans_choice(':count day|:count days', (int) config('platform.access_request_retention_days')), 'job' => 'access-requests:prune'],
            ['data' => __('Analytics events and visits'), 'keeps' => __('Per the analytics retention settings'), 'job' => 'analytics:prune'],
            ['data' => __('Telemetry events, traces and payloads'), 'keeps' => __('Per each account’s Monitoring plan'), 'job' => 'telemetry:prune'],
            ['data' => __('Platform database backups'), 'keeps' => __(':count local copies; off-site for :days days', ['count' => (int) config('platform.backups.keep_local'), 'days' => (int) config('platform.backups.keep_remote_days')]), 'job' => 'platform:backup'],
            ['data' => __('Server command output'), 'keeps' => __('Per the Infrastructure settings'), 'job' => 'servers:prune-commands'],
        ];
    }

    /**
     * Get each database queue's backlog: the configured queues always, plus any other queue with jobs.
     *
     * @return list<QueueState>
     */
    public function queues(): array
    {
        $limit = max(1, (int) config('platform.queue_backlog_limit'));
        $ageLimit = max(1, (int) config('platform.queue_oldest_minutes'));
        try {
            $rows = DB::table('jobs')->selectRaw('queue, SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN reserved_at IS NULL THEN 0 ELSE 1 END) AS reserved, MIN(CASE WHEN reserved_at IS NULL THEN available_at END) AS oldest')
                ->groupBy('queue')->get()->keyBy('queue');
        } catch (Throwable) {
            return [];
        }
        $names = array_values(array_unique([...array_map('strval', (array) config('platform.queues')), ...$rows->keys()->map(fn (mixed $name): string => (string) $name)->all()]));
        sort($names);

        return array_map(function (string $name) use ($rows, $limit, $ageLimit): QueueState {
            $row = $rows->get($name);
            $pending = (int) ($row->pending ?? 0);
            $oldestAt = $row?->oldest;
            $oldest = is_numeric($oldestAt) ? max(0, intdiv(now()->getTimestamp() - (int) $oldestAt, 60)) : 0;

            return new QueueState($name, $pending, (int) ($row->reserved ?? 0), $oldest, $pending <= $limit && $oldest <= $ageLimit);
        }, $names);
    }

    /**
     * Check the database answers.
     *
     * @return HealthCheck
     */
    private function database(): HealthCheck
    {
        try {
            DB::select('select 1');

            return new HealthCheck('Database connection', 'connectivity', true, DB::getDriverName());
        } catch (Throwable) {
            return new HealthCheck('Database connection', 'connectivity', false, 'Unavailable');
        }
    }

    /**
     * Check every migration has run.
     *
     * @return HealthCheck
     */
    private function migrations(): HealthCheck
    {
        try {
            $files = array_keys($this->migrator->getMigrationFiles([database_path('migrations')]));
            $pending = count(array_diff($files, $this->migrator->getRepository()->getRan()));

            return new HealthCheck('Database migrations', 'runtime', $pending === 0, $pending === 0 ? 'Current' : trans_choice(':count migration hasn’t run|:count migrations haven’t run', $pending));
        } catch (Throwable) {
            return new HealthCheck('Database migrations', 'runtime', false, 'Unavailable');
        }
    }

    /**
     * Check a directory exists and is writable.
     *
     * @param  string  $name
     * @param  string  $path
     * @return HealthCheck
     */
    private function writable(string $name, string $path): HealthCheck
    {
        $writable = is_dir($path) && is_writable($path);

        return new HealthCheck($name, 'storage', $writable, $writable ? 'Writable' : 'Missing or not writable');
    }

    /**
     * Count failed jobs, or null when they can't be read.
     *
     * @return int|null
     */
    private function failedJobCount(): ?int
    {
        try {
            return DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            return null;
        }
    }
}
