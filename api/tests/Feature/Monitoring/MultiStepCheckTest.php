<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Models\Monitor;
use App\Models\Project;
use App\Services\Monitoring\ProbeMonitor;
use App\Support\Monitoring\FlowSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MultiStepCheckTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The login flow the tests run.
     *
     * @var string
     */
    private const STEPS = <<<'STEPS'
GET https://shop.example/login
expect 200 "Sign in"
extract token name="_token" value="([^"]+)"

POST https://shop.example/login
form email=monitor@example.com&password=s3cret&_token={{token}}

GET https://shop.example/account
expect 200 "Your orders"
STEPS;

    /**
     * Check a multi-step check is saved with its steps encrypted, bad steps and private addresses are refused, and a
     * run carries cookies and extracted values between steps, follows redirects, and reports the step that failed.
     *
     * @return void
     */
    public function test_a_login_flow_is_checked_step_by_step(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.216.34']);
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->firstOrFail();
        $base = "/api/app/projects/{$project->id}/monitoring";
        $fields = ['check_type' => 'flow', 'name' => 'Checkout login', 'environment_id' => $environment->id, 'interval_minutes' => 5, 'timeout_seconds' => 10, 'trigger_checks' => 2, 'recovery_checks' => 2, 'enabled' => '1', 'opened' => '1', 'recovered' => '1'];

        $this->actingAs($owner)->getJson("{$base}/monitors/create?check_type=flow")->assertOk()->assertJsonPath('types.flow', __('Multi-step check'));
        $this->assertStringContainsString('_token={{token}}', (string) $this->getJson("{$base}/monitors/create?check_type=flow")->json('flowExample'));
        $this->actingAs($owner)->postJson("{$base}/monitors", [...$fields, 'flow_steps' => 'FETCH https://shop.example/'])->assertJsonValidationErrors('flow_steps');
        $this->actingAs($owner)->postJson("{$base}/monitors", [...$fields, 'flow_steps' => "GET https://shop.example/\nexpect nope"])->assertJsonValidationErrors('flow_steps');
        $this->actingAs($owner)->postJson("{$base}/monitors", [...$fields, 'flow_steps' => self::STEPS])->assertSuccessful();
        $monitor = Monitor::query()->sole();
        $this->assertSame(['flow', 3], [$monitor->type, count(FlowSteps::parse((string) $monitor->flow_steps)['steps'])]);
        $this->assertStringNotContainsString('s3cret', (string) $monitor->getRawOriginal('flow_steps'), 'Steps are encrypted.');
        $this->assertSame('shop.example · 3 steps', $monitor->targetLabel());

        Http::fake(function (Request $request) {
            return match (true) {
                $request->url() === 'https://shop.example/login' && $request->method() === 'GET' => Http::response('<h1>Sign in</h1><input name="_token" value="abc123">', 200, ['Set-Cookie' => 'session=s1; Path=/']),
                $request->url() === 'https://shop.example/login' && $request->method() === 'POST' => str_contains($request->body(), '_token=abc123') && str_contains($request->header('Cookie')[0] ?? '', 'session=s1')
                    ? Http::response('', 302, ['Location' => '/account', 'Set-Cookie' => 'auth=yes; Path=/'])
                    : Http::response('Bad token', 419),
                $request->url() === 'https://shop.example/account' => str_contains($request->header('Cookie')[0] ?? '', 'auth=yes') ? Http::response('Your orders') : Http::response('Sign in', 200),
                default => Http::response('', 404),
            };
        });
        $observation = app(ProbeMonitor::class)->probe($monitor);
        $this->assertSame(['up', 'passed'], [$observation->outcome, $observation->reason]);

        $monitor->forceFill(['flow_steps' => str_replace('Your orders', 'Your invoices', self::STEPS)])->save();
        $observation = app(ProbeMonitor::class)->probe($monitor->refresh());
        $this->assertSame(['down', 'body_mismatch', 3], [$observation->outcome, $observation->reason, $observation->details['failed_step'] ?? null]);
    }
}
