<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalDiagnosticsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_report_safe_human_and_json_readiness(): void
    {
        config([
            'app.key' => 'base64:must-never-appear-in-output',
            'lessbuild.diagnostics.systemd_timers' => false,
        ]);

        $this->artisan('lessbuild:diagnose')
            ->expectsOutputToContain('Application key')
            ->expectsOutputToContain('Database migrations')
            ->expectsOutputToContain('Lessbuild diagnostics passed.')
            ->doesntExpectOutputToContain('must-never-appear-in-output')
            ->assertSuccessful();

        $this->assertSame(0, Artisan::call('lessbuild:diagnose', ['--json' => true]));
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ready', $payload['status']);
        $this->assertCount(14, $payload['checks']);
        $this->assertSame(
            'Systemd inspection is not enabled',
            collect($payload['checks'])->firstWhere('name', 'Application services')['detail'],
        );
        $this->assertSame(
            'Systemd inspection is not enabled',
            collect($payload['checks'])->firstWhere('name', 'Automation timers')['detail'],
        );
        $this->assertStringNotContainsString('must-never-appear-in-output', $output);
    }

    public function test_diagnostics_verify_required_systemd_timers_when_enabled(): void
    {
        config([
            'lessbuild.diagnostics.systemd_timers' => true,
            'lessbuild.diagnostics.systemd_services' => [
                'lessbuild-app.service',
                'lessbuild-worker.service',
            ],
        ]);
        Process::fake(fn ($process) => in_array('is-active', $process->command, true)
            ? Process::result(output: "active\nactive\nactive\n")
            : Process::result(output: "enabled\nenabled\nenabled\n"));

        $this->assertSame(0, Artisan::call('lessbuild:diagnose', ['--json' => true]));
        $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks']);
        $check = $checks->firstWhere('name', 'Automation timers');

        $this->assertTrue($check['passed']);
        $this->assertSame('3 required systemd timers are enabled and active', $check['detail']);
        $serviceCheck = $checks->firstWhere('name', 'Application services');
        $this->assertTrue($serviceCheck['passed']);
        $this->assertSame('2 required systemd services are enabled and active', $serviceCheck['detail']);

        $expectedServices = ['lessbuild-app.service', 'lessbuild-worker.service'];
        Process::assertRan(fn ($process): bool => $process->command === ['systemctl', 'is-active', ...$expectedServices]);
        Process::assertRan(fn ($process): bool => $process->command === ['systemctl', 'is-enabled', ...$expectedServices]);
        $expectedUnits = ['lessbuild-watchdog.timer', 'lessbuild-health.timer', 'lessbuild-backup.timer'];
        Process::assertRan(fn ($process): bool => $process->command === ['systemctl', 'is-active', ...$expectedUnits]);
        Process::assertRan(fn ($process): bool => $process->command === ['systemctl', 'is-enabled', ...$expectedUnits]);
    }

    public function test_diagnostics_fail_safely_when_a_required_timer_is_inactive(): void
    {
        config(['lessbuild.diagnostics.systemd_timers' => true]);
        Process::fake(fn ($process) => in_array('is-active', $process->command, true)
            ? Process::result(output: "active\ninactive\nactive\n", exitCode: 3)
            : Process::result(output: "enabled\nenabled\nenabled\n"));

        $this->assertSame(1, Artisan::call('lessbuild:diagnose', ['--json' => true]));
        $output = Artisan::output();
        $check = collect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['checks'])
            ->firstWhere('name', 'Automation timers');

        $this->assertFalse($check['passed']);
        $this->assertSame('One or more required systemd timers are disabled or inactive', $check['detail']);
        $this->assertStringNotContainsString('inactive\n', $output);
    }

    public function test_diagnostics_can_monitor_the_public_reverse_proxy(): void
    {
        config([
            'lessbuild.diagnostics.systemd_timers' => true,
            'lessbuild.diagnostics.systemd_services' => [
                'lessbuild-app.service',
                'lessbuild-worker.service',
                'caddy.service',
            ],
        ]);
        Process::fake(fn () => Process::result(output: "active\nactive\nactive\n"));

        $this->assertSame(0, Artisan::call('lessbuild:diagnose', ['--json' => true]));
        $check = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])
            ->firstWhere('name', 'Application services');

        $this->assertTrue($check['passed']);
        $this->assertSame('3 required systemd services are enabled and active', $check['detail']);
        Process::assertRan(fn ($process): bool => $process->command === [
            'systemctl',
            'is-active',
            'lessbuild-app.service',
            'lessbuild-worker.service',
            'caddy.service',
        ]);
    }

    public function test_diagnostics_fail_safely_when_a_required_service_is_inactive(): void
    {
        config([
            'lessbuild.diagnostics.systemd_timers' => true,
            'lessbuild.diagnostics.systemd_services' => [
                'lessbuild-app.service',
                'lessbuild-worker.service',
            ],
        ]);
        Process::fake(function ($process) {
            $isServiceCheck = in_array('lessbuild-app.service', $process->command, true);
            $isActiveCheck = in_array('is-active', $process->command, true);

            return $isServiceCheck && $isActiveCheck
                ? Process::result(output: "active\ninactive\n", exitCode: 3)
                : Process::result(output: "active\nactive\nactive\n");
        });

        $this->assertSame(1, Artisan::call('lessbuild:diagnose', ['--json' => true]));
        $output = Artisan::output();
        $check = collect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['checks'])
            ->firstWhere('name', 'Application services');

        $this->assertFalse($check['passed']);
        $this->assertSame('One or more required systemd services are disabled or inactive', $check['detail']);
        $this->assertStringNotContainsString('inactive\n', $output);
    }

    public function test_diagnostics_fail_without_disclosing_invalid_configuration_values(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => '',
            'app.url' => 'secret-invalid-url-value',
            'app.debug' => true,
            'queue.default' => 'sync',
        ]);

        $this->assertSame(1, Artisan::call('lessbuild:diagnose', ['--json' => true]));
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $failed = collect($payload['checks'])->where('passed', false)->pluck('name')->all();

        $this->assertSame('failed', $payload['status']);
        $this->assertContains('Application key', $failed);
        $this->assertContains('Application URL', $failed);
        $this->assertContains('Debug mode', $failed);
        $this->assertContains('Queue connection', $failed);
        $this->assertStringNotContainsString('secret-invalid-url-value', $output);
    }

    public function test_database_failures_are_reported_without_exception_details(): void
    {
        $defaultConnection = config('database.default');
        try {
            config(['database.default' => 'missing-secret-connection']);
            $exitCode = Artisan::call('lessbuild:diagnose', ['--json' => true]);
            $output = Artisan::output();
        } finally {
            config(['database.default' => $defaultConnection]);
        }

        $this->assertSame(1, $exitCode);
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $database = collect($payload['checks'])->firstWhere('name', 'Database connection');

        $this->assertFalse($database['passed']);
        $this->assertSame('Unavailable', $database['detail']);
        $this->assertStringNotContainsString('missing-secret-connection', $output);
    }

    public function test_database_queue_backlog_and_failures_are_bounded_without_reading_payloads(): void
    {
        config([
            'queue.default' => 'database',
            'queue.failed.database' => config('database.default'),
            'lessbuild.diagnostics.queue_backlog_limit' => 1,
            'lessbuild.diagnostics.queue_oldest_minutes' => 5,
        ]);
        $now = now()->timestamp;
        foreach ([1, 2] as $id) {
            \DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => "pending-secret-payload-{$id}",
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now - 600,
            ]);
        }
        \DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'failed-secret-payload',
            'exception' => 'failed-secret-exception',
            'failed_at' => now(),
        ]);

        $this->assertSame(1, Artisan::call('lessbuild:diagnose', ['--json' => true]));
        $output = Artisan::output();
        $checks = collect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['checks']);

        $this->assertFalse($checks->firstWhere('name', 'Pending queue state')['passed']);
        $this->assertSame('2 pending jobs; oldest 10m; limits 1 jobs / 5m', $checks->firstWhere('name', 'Pending queue state')['detail']);
        $this->assertFalse($checks->firstWhere('name', 'Failed queue jobs')['passed']);
        $this->assertSame('1 failed job requires review', $checks->firstWhere('name', 'Failed queue jobs')['detail']);
        $this->assertStringNotContainsString('secret-payload', $output);
        $this->assertStringNotContainsString('secret-exception', $output);
    }
}
