<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\Project;
use App\Support\Monitoring\QueueMonitorSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Monitor> */
class MonitorFactory extends Factory
{
    protected $model = Monitor::class;

    /** The production environment of a new project with Monitoring turned on. */
    public static function environment(?Project $project = null): string
    {
        $project ??= Project::factory()->withServices(['monitoring'])->create();

        return $project->environments()->where('slug', 'production')->valueOrFail('id');
    }

    public function queueMonitor(): static
    {
        return $this->state(fn (): array => [
            'type' => 'queue', 'name' => 'Email queue', 'request_url' => null, 'queue_name' => 'emails',
            'queue_settings' => QueueMonitorSettings::DEFAULTS, 'queue_token_hash' => hash('sha256', 'test-queue-monitor-key'),
            'queue_started_at' => now('UTC'), 'queue_snapshot_id' => null, 'next_check_at' => now('UTC')->addSeconds(120),
            'trigger_checks' => 1, 'recovery_checks' => 1, 'interval_minutes' => 1, 'timeout_seconds' => 1,
        ]);
    }

    public function heartbeat(): static
    {
        return $this->state(fn (): array => [
            'type' => 'heartbeat', 'name' => 'Nightly import', 'request_url' => null,
            'heartbeat_schedule' => 'interval', 'heartbeat_interval_minutes' => 60,
            'heartbeat_timezone' => 'UTC', 'heartbeat_grace_minutes' => 5,
            'heartbeat_token_hash' => hash('sha256', 'test-heartbeat-key'),
            'heartbeat_due_at' => now('UTC')->addHour(), 'next_check_at' => now('UTC')->addMinutes(65),
            'trigger_checks' => 1, 'recovery_checks' => 1, 'interval_minutes' => 1, 'timeout_seconds' => 1,
        ]);
    }

    public function dns(): static
    {
        return $this->state([
            'type' => 'dns', 'name' => 'DNS records', 'request_url' => null, 'hostname' => 'status.example.com',
            'dns_record_type' => 'A', 'dns_match' => 'exact', 'dns_expected' => ['1.1.1.1'],
        ]);
    }

    public function tls(): static
    {
        return $this->state([
            'type' => 'tls', 'name' => 'TLS certificate', 'request_url' => null, 'hostname' => 'status.example.com',
            'tls_port' => 443, 'tls_expiry_days' => 14,
        ]);
    }

    public function tcp(): static
    {
        return $this->state([
            'type' => 'tcp', 'name' => 'TCP port', 'request_url' => null, 'hostname' => 'status.example.com',
            'tcp_port' => 5432,
        ]);
    }

    public function paused(): static
    {
        return $this->state(['enabled' => false, 'next_check_at' => null]);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'environment_id' => fn (): string => self::environment(), 'name' => 'Public API health',
            'type' => 'http', 'request_url' => 'https://status.example.com/health',
            'bearer_token' => null, 'body_contains' => null, 'max_duration_ms' => null,
            'method' => 'GET', 'status_min' => 200, 'status_max' => 299, 'timeout_seconds' => 10,
            'interval_minutes' => 5, 'trigger_checks' => 2, 'recovery_checks' => 2, 'enabled' => true,
            'state_version' => 0, 'config_revision' => 0, 'health' => 'unknown',
            'failure_streak' => 0, 'recovery_streak' => 0, 'next_check_at' => now('UTC'),
        ];
    }
}
