<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\Issue;
use App\Models\Project;
use App\Models\TelemetryEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class BrowserErrorsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check browser error tracking is turned on with origins, the page script's errors from those origins become one
     * issue per distinct error from the "browser" service (tied to the release), other origins and keys are refused,
     * and turning it off stops the key working.
     *
     * @return void
     */
    public function test_browser_errors_become_issues(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->firstOrFail();
        $url = "/projects/{$project->id}/monitoring/environments/{$environment->id}/browser";

        $this->actingAs($owner)->put($url, ['enabled' => '1', 'origins' => 'example.com'])->assertSessionHasErrors('origins');
        $this->actingAs($owner)->put($url, ['enabled' => '1', 'origins' => 'https://Example.com/, https://www.example.com'])->assertRedirect();
        $environment->refresh();
        $this->assertSame(['https://example.com', 'https://www.example.com'], $environment->browser_origins);
        $key = (string) $environment->browser_key;
        $this->assertStringStartsWith('bpb_', $key);
        $this->actingAs($owner)->get("/projects/{$project->id}/monitoring/setup")->assertOk()->assertSee('data-key=&quot;'.$key.'&quot;', false);

        $error = ['message' => 'TypeError: cart is undefined', 'source' => 'https://example.com/app.js?v=3', 'line' => 12, 'column' => 7, 'stack' => "TypeError\n at checkout (app.js:12:7)", 'page' => 'https://example.com/checkout?token=secret'];
        $send = fn (string $origin, array $body, string $to = '') => $this->withHeaders(['Origin' => $origin, 'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15'])
            ->postJson('/api/v1/browser/'.($to ?: $key).'/errors', $body);
        $send('https://example.com', ['release' => 'v1.4.0', 'errors' => [$error, $error, ['message' => 'Network down', 'kind' => 'unhandledrejection']]])->assertStatus(202)->assertHeader('Access-Control-Allow-Origin');
        $this->withHeaders(['Origin' => 'https://example.com', 'Access-Control-Request-Method' => 'POST'])->options("/api/v1/browser/{$key}/errors")->assertSuccessful();
        $send('https://evil.example', ['errors' => [$error]])->assertForbidden();
        $send('https://example.com', ['errors' => [$error]], 'bpb_unknown')->assertNotFound();
        $send('https://example.com', ['errors' => []])->assertUnprocessable();

        $events = TelemetryEvent::query()->where('environment_id', $environment->id)->where('type', 'exception')->get();
        $this->assertCount(3, $events);
        $this->assertSame(['browser'], $events->pluck('service')->unique()->values()->all());
        $this->assertSame(2, Issue::query()->where('environment_id', $environment->id)->count(), 'The same error twice is one issue.');
        $first = $events->firstOrFail();
        $this->assertSame('/checkout', $first->route);
        $this->assertStringNotContainsString('secret', (string) json_encode($first->payload), 'Query strings are dropped from the page address.');
        $this->assertSame('v1.4.0', $first->release?->version);

        $this->actingAs($owner)->put($url, ['enabled' => '0'])->assertRedirect();
        $send('https://example.com', ['errors' => [$error]])->assertNotFound();
    }
}
