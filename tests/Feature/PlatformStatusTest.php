<?php

namespace Tests\Feature;

use App\Services\OperationalDiagnostics;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('buildpusher:public-platform-status:v1');
    }

    public function test_public_platform_status_shows_safe_service_level_information(): void
    {
        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn($this->checks());

        $this->get(route('platform-status.show'))
            ->assertOk()
            ->assertHeader('cache-control', 'max-age=30, public')
            ->assertHeaderMissing('set-cookie')
            ->assertSee('All systems operational')
            ->assertSee('Website')
            ->assertSee('API')
            ->assertSee('Deployments')
            ->assertSee('Webhooks')
            ->assertSee('Background jobs')
            ->assertDontSee('Database connection')
            ->assertDontSee('sqlite')
            ->assertDontSee('Application key');
    }

    public function test_public_json_report_exposes_statuses_but_not_diagnostic_details(): void
    {
        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn($this->checks(['Pending queue state']));

        $response = $this->getJson(route('platform-status.report'))
            ->assertOk()
            ->assertHeader('cache-control', 'max-age=30, public')
            ->assertHeaderMissing('set-cookie')
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('components.0.name', 'Website')
            ->assertJsonPath('components.2.status', 'Degraded')
            ->assertJsonPath('components.3.status', 'Degraded')
            ->assertJsonPath('components.4.status', 'Degraded');

        $body = $response->getContent();
        $this->assertStringNotContainsString('Pending queue state', $body);
        $this->assertStringNotContainsString('queue payload secret', $body);
        $this->assertStringNotContainsString('Database connection', $body);
    }

    /** @return list<array{name: string, passed: bool, detail: string}> */
    private function checks(array $failed = []): array
    {
        return collect([
            'Application key', 'Application URL', 'Database connection', 'Database migrations',
            'Storage directory', 'Bootstrap cache', 'Debug mode', 'Queue connection',
            'Pending queue state', 'Failed queue jobs', 'Application services', 'Automation timers',
        ])->map(fn (string $name): array => [
            'name' => $name,
            'passed' => ! in_array($name, $failed, true),
            'detail' => $name === 'Pending queue state' ? 'queue payload secret' : 'internal detail',
        ])->all();
    }
}
