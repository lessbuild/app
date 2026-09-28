<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Actions\Accounts\CreateAccount;
use App\Actions\Monitoring\RecordHeartbeat;
use App\Actions\Monitoring\RotateHeartbeatToken;
use App\Actions\Monitoring\SaveAlertDestination;
use App\Actions\Monitoring\SaveMonitor;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\EnableService;
use App\Actions\Telemetry\CreateIngestToken;
use App\Contracts\Telemetry\TelemetryIngestor;
use App\Data\Projects\ProjectDetails;
use App\Data\Telemetry\IngestContext;
use App\Enums\AlertDestinationType;
use App\Jobs\Admin\ReportPlatformException;
use App\Models\Account;
use App\Models\Environment;
use App\Models\Monitor;
use App\Models\PlatformSetting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * BuildPusher watching itself with its own Monitoring: an operations account holds an uptime check on the site, a
 * heartbeat the scheduler keeps alive every minute, email alerts to the admin who set it up, and an ingestion key the
 * platform's own exceptions are reported under. Reporting never throws, never reports its own failures, and sends
 * each distinct error at most once a minute.
 */
final class SelfMonitoring
{
    /**
     * The platform setting holding the operations account's details.
     *
     * @var string
     */
    public const SETTING = 'self_monitoring';

    /**
     * Whether an exception is being reported now, so a failure while reporting isn't reported again.
     *
     * @var bool
     */
    private static bool $reporting = false;

    /**
     * Create a new SelfMonitoring instance.
     *
     * @param  CreateAccount  $accounts  Creates the operations account.
     * @param  CreateProject  $projects  Creates its project.
     * @param  EnableService  $services  Turns Monitoring on for it.
     * @param  SaveMonitor  $monitors  Creates the uptime check and the scheduler heartbeat.
     * @param  RotateHeartbeatToken  $heartbeatTokens  Gives the heartbeat its key.
     * @param  SaveAlertDestination  $destinations  Emails the admin.
     * @param  CreateIngestToken  $ingestTokens  Issues the key exceptions are reported under.
     */
    public function __construct(
        private readonly CreateAccount $accounts,
        private readonly CreateProject $projects,
        private readonly EnableService $services,
        private readonly SaveMonitor $monitors,
        private readonly RotateHeartbeatToken $heartbeatTokens,
        private readonly SaveAlertDestination $destinations,
        private readonly CreateIngestToken $ingestTokens,
    ) {}

    /**
     * Set self-monitoring up, owned by an admin (or check it's still set up). Safe to run again.
     *
     * @param  User  $owner  A platform admin with a verified email; alerts go to them.
     * @return array{account_id: string, project_id: string, environment_id: string, uptime_monitor_id: int, heartbeat_monitor_id: int, ingest_token_id: int}
     */
    public function setUp(User $owner): array
    {
        $existing = $this->settings();
        if ($existing !== null && Monitor::query()->whereKey($existing['heartbeat_monitor_id'])->exists()) {
            return $existing;
        }
        $account = $this->accounts->handle($owner, config('app.name').' operations');
        $project = $this->projects->handle($owner, $account, new ProjectDetails(parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.name'), __('The platform watching itself.')));
        $this->services->handle($owner, $project, 'monitoring');
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $destination = $this->destinations->handle($account, $owner, ['type' => AlertDestinationType::Email->value, 'name' => __('Platform admin'), 'enabled' => true, 'recipient_user_id' => $owner->id]);
        $alerts = ['environment_id' => $environment->id, 'enabled' => true, 'destinations' => [$destination->id], 'opened' => true, 'recovered' => true];
        $uptime = $this->monitors->handle($project, $owner, [...$alerts, 'check_type' => 'http', 'name' => __('Site is up'),
            'request_url' => rtrim((string) config('app.url'), '/').'/up', 'method' => 'GET', 'status_min' => 200, 'status_max' => 299,
            'timeout_seconds' => 10, 'interval_minutes' => 1, 'trigger_checks' => 2, 'recovery_checks' => 1]);
        $heartbeat = $this->monitors->handle($project, $owner, [...$alerts, 'check_type' => 'heartbeat', 'name' => __('Scheduler runs'),
            'heartbeat_schedule' => 'interval', 'heartbeat_interval_minutes' => 1, 'heartbeat_grace_minutes' => 3, 'trigger_checks' => 1, 'recovery_checks' => 1]);
        $this->heartbeatTokens->handle($owner, $heartbeat, $heartbeat->refresh()->state_version);
        $token = $this->ingestTokens->handle($owner, $environment, __('Platform exceptions'));
        $settings = ['account_id' => $account->id, 'project_id' => $project->id, 'environment_id' => $environment->id, 'uptime_monitor_id' => $uptime->id, 'heartbeat_monitor_id' => $heartbeat->id, 'ingest_token_id' => $token->token->id];
        PlatformSetting::write(self::SETTING, $settings);

        return $settings;
    }

    /**
     * Tell the scheduler heartbeat monitor the scheduler ran (every minute), when self-monitoring is set up.
     *
     * @param  RecordHeartbeat  $record
     * @return bool whether a heartbeat was recorded
     */
    public function beat(RecordHeartbeat $record): bool
    {
        $monitor = ($settings = $this->settings()) !== null ? Monitor::query()->whereKey($settings['heartbeat_monitor_id'])->first() : null;
        if ($monitor === null || $monitor->heartbeat_token_hash === null) {
            return false;
        }
        $record->handle($monitor->id, $monitor->heartbeat_token_hash, (string) Str::uuid(), 'success');

        return true;
    }

    /**
     * Queue an exception to be reported into the operations project, at most once a minute per distinct error.
     * Never throws.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        if (self::$reporting || $exception instanceof \Illuminate\Validation\ValidationException) {
            return;
        }
        self::$reporting = true;
        try {
            if ($this->settings() === null) {
                return;
            }
            $fingerprint = sha1($exception::class.'|'.$exception->getFile().'|'.$exception->getLine());
            if (! Cache::add('self-monitoring.reported.'.$fingerprint, true, 60)) {
                return;
            }
            $request = app()->runningInConsole() ? null : request();
            ReportPlatformException::dispatch([
                'id' => (string) Str::uuid(), 'type' => 'exception', 'severity' => 'error', 'service' => 'platform',
                'name' => Str::limit($exception::class, 255, ''), 'title' => Str::limit($exception->getMessage() !== '' ? $exception->getMessage() : class_basename($exception), 255, ''),
                'fingerprint' => $fingerprint, 'timestamp' => now('UTC')->toIso8601String(),
                'route' => $request?->route()?->getName(), 'url' => $request !== null ? Str::limit($request->url(), 2048, '') : null,
                'details' => Str::limit($exception::class.' in '.$exception->getFile().':'.$exception->getLine()."\n".$exception->getTraceAsString(), 10000, ''),
            ]);
        } catch (Throwable) {
            // Reporting must never make things worse.
        } finally {
            self::$reporting = false;
        }
    }

    /**
     * Put an exception event into the operations project's telemetry.
     *
     * @param  array<string, mixed>  $event
     * @param  TelemetryIngestor  $ingestor
     * @return void
     */
    public function ingest(array $event, TelemetryIngestor $ingestor): void
    {
        $settings = $this->settings();
        $environment = $settings !== null ? Environment::query()->find($settings['environment_id']) : null;
        if ($environment === null) {
            return;
        }
        $ingestor->ingest($environment, 'platform-'.$event['id'], [$event], new IngestContext(tokenId: $settings['ingest_token_id']));
    }

    /**
     * Get the operations account's details, or null when self-monitoring isn't set up.
     *
     * @return array{account_id: string, project_id: string, environment_id: string, uptime_monitor_id: int, heartbeat_monitor_id: int, ingest_token_id: int}|null
     */
    public function settings(): ?array
    {
        $settings = PlatformSetting::read(self::SETTING);
        if (! is_array($settings) || ! isset($settings['account_id'], $settings['project_id'], $settings['environment_id'], $settings['uptime_monitor_id'], $settings['heartbeat_monitor_id'], $settings['ingest_token_id'])) {
            return null;
        }

        return [
            'account_id' => (string) $settings['account_id'], 'project_id' => (string) $settings['project_id'], 'environment_id' => (string) $settings['environment_id'],
            'uptime_monitor_id' => (int) $settings['uptime_monitor_id'], 'heartbeat_monitor_id' => (int) $settings['heartbeat_monitor_id'], 'ingest_token_id' => (int) $settings['ingest_token_id'],
        ];
    }

    /**
     * Get the operations project, when self-monitoring is set up.
     *
     * @return Project|null
     */
    public function project(): ?Project
    {
        return ($settings = $this->settings()) !== null ? Project::query()->find($settings['project_id']) : null;
    }

    /**
     * Get the operations account, when self-monitoring is set up.
     *
     * @return Account|null
     */
    public function account(): ?Account
    {
        return ($settings = $this->settings()) !== null ? Account::query()->find($settings['account_id']) : null;
    }
}
