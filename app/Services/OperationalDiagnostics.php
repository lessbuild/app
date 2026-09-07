<?php

namespace App\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Process;
use Throwable;

class OperationalDiagnostics
{
    /**
     * Bind readiness probes used by operational diagnostics.
     *
     * @param  ApplicationReadiness  $readiness  Reports whether the application is ready to serve traffic.
     * @param  DatabaseManager  $database  Supplies configured database connections.
     * @param  EmailReadiness  $email  Checks outbound mail configuration readiness.
     * @param  ExternalMonitoring  $externalMonitoring  Checks heartbeat and external status endpoints.
     */
    public function __construct(
        private readonly ApplicationReadiness $readiness,
        private readonly DatabaseManager $database,
        private readonly EmailReadiness $email,
        private readonly ExternalMonitoring $externalMonitoring,
    ) {}

    /** @return list<array{name: string, passed: bool, detail: string}> */
    public function run(): array
    {
        $environment = (string) config('app.env');
        $production = $environment === 'production';
        $url = (string) config('app.url');
        $queue = (string) config('queue.default');
        $validUrl = in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && is_string(parse_url($url, PHP_URL_HOST));
        $migrationsReady = $this->readiness->isReady();

        return [
            $this->result('Application key', filled(config('app.key')), filled(config('app.key')) ? 'Configured' : 'Missing'),
            $this->result(
                'Application URL',
                $validUrl,
                $validUrl ? 'Valid HTTP(S) URL' : 'Must include an HTTP(S) scheme and host',
            ),
            $this->databaseCheck(),
            $this->result(
                'Database migrations',
                $migrationsReady,
                $migrationsReady ? 'Current' : 'Pending, unavailable, or not initialized',
            ),
            $this->writableCheck('Storage directory', storage_path()),
            $this->writableCheck('Bootstrap cache', base_path('bootstrap/cache')),
            $this->result(
                'Debug mode',
                ! $production || ! (bool) config('app.debug'),
                $production && config('app.debug') ? 'Must be disabled in production' : ($production ? 'Disabled in production' : "Environment: {$environment}"),
            ),
            $this->result(
                'Queue connection',
                ! $production || $queue !== 'sync',
                $production && $queue === 'sync' ? 'Production queue must be asynchronous' : $queue,
            ),
            $this->email->check(),
            $this->externalMonitoring->configurationCheck(),
            ...$this->queueStateChecks($queue),
            $this->systemServiceCheck(),
            $this->systemTimerCheck(),
        ];
    }

    /** @return array{name: string, passed: bool, detail: string} */
    private function databaseCheck(): array
    {
        try {
            $connection = $this->database->connection();
            $connection->select('select 1');

            return $this->result('Database connection', true, $connection->getDriverName());
        } catch (Throwable) {
            return $this->result('Database connection', false, 'Unavailable');
        }
    }

    /** @return array{name: string, passed: bool, detail: string} */
    private function writableCheck(string $name, string $path): array
    {
        return $this->result($name, is_dir($path) && is_writable($path), is_dir($path) && is_writable($path) ? 'Writable' : 'Missing or not writable');
    }

    /** @return list<array{name: string, passed: bool, detail: string}> */
    private function queueStateChecks(string $connection): array
    {
        $driver = (string) config("queue.connections.{$connection}.driver");
        if ($driver !== 'database') {
            return [
                $this->result('Pending queue state', true, $driver === 'sync' ? 'Runs inline' : 'Inspect the external queue backend'),
                $this->failedJobCheck(),
            ];
        }

        try {
            $database = config("queue.connections.{$connection}.connection") ?: config('database.default');
            $table = (string) config("queue.connections.{$connection}.table", 'jobs');
            $query = $this->database->connection((string) $database)->table($table);
            $count = $query->count();
            $oldest = $query->min('created_at');
            $ageMinutes = is_numeric($oldest) ? max(0, intdiv(now()->timestamp - (int) $oldest, 60)) : 0;
            $countLimit = max(1, (int) config('lessbuild.diagnostics.queue_backlog_limit'));
            $ageLimit = max(1, (int) config('lessbuild.diagnostics.queue_oldest_minutes'));
            $passed = $count <= $countLimit && ($count === 0 || $ageMinutes <= $ageLimit);

            $pending = $count === 1 ? '1 pending job' : "{$count} pending jobs";
            $detail = $count === 0 ? $pending : "{$pending}; oldest {$ageMinutes}m";

            return [
                $this->result('Pending queue state', $passed, $passed ? $detail : "{$detail}; limits {$countLimit} jobs / {$ageLimit}m"),
                $this->failedJobCheck(),
            ];
        } catch (Throwable) {
            return [
                $this->result('Pending queue state', false, 'Unavailable'),
                $this->failedJobCheck(),
            ];
        }
    }

    /** @return array{name: string, passed: bool, detail: string} */
    private function failedJobCheck(): array
    {
        $driver = (string) config('queue.failed.driver');
        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return $this->result('Failed queue jobs', true, 'Inspect the configured failure backend');
        }

        try {
            $connection = (string) (config('queue.failed.database') ?: config('database.default'));
            $table = (string) config('queue.failed.table', 'failed_jobs');
            $count = $this->database->connection($connection)->table($table)->count();

            return $this->result(
                'Failed queue jobs',
                $count === 0,
                $count === 0 ? 'None' : ($count === 1 ? '1 failed job requires review' : "{$count} failed jobs require review"),
            );
        } catch (Throwable) {
            return $this->result('Failed queue jobs', false, 'Unavailable');
        }
    }

    /** @return array{name: string, passed: bool, detail: string} */
    private function systemServiceCheck(): array
    {
        if (! (bool) config('lessbuild.diagnostics.systemd_timers')) {
            return $this->result('Application services', true, 'Systemd inspection is not enabled');
        }

        $units = config('lessbuild.diagnostics.systemd_services', []);
        if (! is_array($units) || $units === []) {
            $units = ['lessbuild-app.service', 'lessbuild-worker.service'];
        }

        return $this->systemdUnitCheck('Application services', array_values($units), 'services');
    }

    /** @return array{name: string, passed: bool, detail: string} */
    private function systemTimerCheck(): array
    {
        if (! (bool) config('lessbuild.diagnostics.systemd_timers')) {
            return $this->result('Automation timers', true, 'Systemd inspection is not enabled');
        }

        try {
            $units = [
                'lessbuild-watchdog.timer',
                'lessbuild-health.timer',
            ];

            if ($this->database->connection()->getDriverName() === 'sqlite') {
                $units[] = 'lessbuild-backup.timer';
            }

            return $this->systemdUnitCheck('Automation timers', $units, 'timers');
        } catch (Throwable) {
            return $this->result('Automation timers', false, 'Unable to inspect required systemd timers');
        }
    }

    /**
     * @param  list<string>  $units
     * @return array{name: string, passed: bool, detail: string}
     */
    private function systemdUnitCheck(string $name, array $units, string $label): array
    {
        try {
            $active = Process::timeout(5)->quietly()->run(['systemctl', 'is-active', ...$units]);
            $enabled = Process::timeout(5)->quietly()->run(['systemctl', 'is-enabled', ...$units]);
            $passed = $active->successful() && $enabled->successful();
            $count = count($units);

            return $this->result(
                $name,
                $passed,
                $passed
                    ? "{$count} required systemd {$label} are enabled and active"
                    : "One or more required systemd {$label} are disabled or inactive",
            );
        } catch (Throwable) {
            return $this->result($name, false, "Unable to inspect required systemd {$label}");
        }
    }

    /** @return array{name: string, passed: bool, detail: string} */
    private function result(string $name, bool $passed, string $detail): array
    {
        return compact('name', 'passed', 'detail');
    }
}
