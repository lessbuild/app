<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Jobs\Monitoring\CheckThirdPartyStatus;
use App\Models\Project;
use App\Models\ThirdPartyService;
use App\Services\Monitoring\ThirdPartyStatusChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ThirdPartyStatusTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check a project follows GitHub and a custom status page, each page is read once however many projects follow
     * it, outages show with their components and incident, private and bad addresses are refused, and a broken
     * status page keeps the last known status with the error.
     *
     * @return void
     */
    public function test_third_party_status_is_followed_and_checked(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.216.34']);
        Http::fake([
            'www.githubstatus.com/*' => Http::sequence()
                ->push(['status' => ['indicator' => 'major', 'description' => 'Partial System Outage'], 'components' => [['name' => 'Actions', 'status' => 'major_outage'], ['name' => 'Git Operations', 'status' => 'operational']], 'incidents' => [['name' => 'Delayed Actions runs']], 'scheduled_maintenances' => []])
                ->push('Bad gateway', 502),
            'status.example.com/*' => Http::response(['status' => ['indicator' => 'none', 'description' => 'All Systems Operational'], 'components' => [], 'incidents' => [], 'scheduled_maintenances' => []]),
        ]);
        Queue::fake();
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $other = Project::factory()->withServices(['monitoring'])->create();
        $base = "/projects/{$project->id}/monitoring";

        $this->actingAs($owner)->post("{$base}/third-party", ['provider' => 'github'])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/third-party", ['provider' => 'github'])->assertSessionHasErrors('provider');
        $this->actingAs($owner)->post("{$base}/third-party", ['provider' => 'custom', 'name' => 'Payments', 'url' => 'https://status.example.com'])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/third-party", ['provider' => 'custom', 'name' => 'Local', 'url' => 'http://status.example.com'])->assertSessionHasErrors('url');
        $this->actingAs($owner)->post("{$base}/third-party", ['provider' => 'nope'])->assertSessionHasErrors('provider');
        (new ThirdPartyService)->forceFill(['project_id' => $other->id, 'provider' => 'github', 'name' => 'GitHub', 'url' => 'https://www.githubstatus.com'])->save();

        Queue::assertPushed(CheckThirdPartyStatus::class, 2);
        $this->assertSame(2, app(ThirdPartyStatusChecker::class)->checkAll(), 'Two addresses, three rows.');
        $github = ThirdPartyService::query()->where('project_id', $project->id)->where('provider', 'github')->sole();
        $this->assertSame(['major', ['Actions'], 'Delayed Actions runs'], [$github->indicator, $github->affected, $github->incident]);
        $this->assertSame('major', ThirdPartyService::query()->where('project_id', $other->id)->sole()->indicator);
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Services you depend on')->assertSee('Major outage')->assertSee('Delayed Actions runs')->assertSee('Payments')->assertSee(__('Operational'));

        app(ThirdPartyStatusChecker::class)->check('https://www.githubstatus.com');
        $github->refresh();
        $this->assertSame('major', $github->indicator, 'A failed check keeps the last status.');
        $this->assertStringContainsString('HTTP 502', (string) $github->last_error);

        $this->actingAs($owner)->delete("{$base}/third-party/{$github->id}")->assertRedirect();
        $this->actingAs($owner)->delete("{$base}/third-party/".ThirdPartyService::query()->where('project_id', $other->id)->sole()->id)->assertNotFound();
        $this->assertSame(2, ThirdPartyService::query()->count());
    }
}
