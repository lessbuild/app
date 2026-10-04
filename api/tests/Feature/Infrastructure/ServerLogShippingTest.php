<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Models\IngestToken;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerLogShipping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServerLogShippingTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check a server's logs are sent to a Monitoring environment through an agent with its own key (installed with
     * the key, never shown), reinstalling replaces the key, a failed install is reported, other accounts' environments
     * are refused, and stopping revokes the key and removes the agent.
     *
     * @return void
     */
    public function test_server_logs_are_shipped_to_monitoring(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['infrastructure', 'monitoring'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $environment = $project->environments()->firstOrFail();
        $foreign = Project::factory()->withServices(['monitoring'])->create()->environments()->firstOrFail();
        $url = "/api/app/projects/{$project->id}/infrastructure/servers/{$server->id}/log-shipping";

        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/infrastructure/servers/{$server->id}")->assertOk()->assertJsonPath('logShipping', null)->assertJsonCount(1, 'logEnvironments');
        $this->actingAs($owner)->putJson($url, ['environment_id' => $foreign->id])->assertNotFound();
        $this->actingAs($owner)->putJson($url, ['environment_id' => $environment->id])->assertSuccessful();

        $shipping = ServerLogShipping::query()->sole();
        $this->assertSame('active', $shipping->status);
        $token = IngestToken::query()->findOrFail($shipping->ingest_token_id);
        $this->assertSame([$environment->id, 'Server logs: '.$server->name], [$token->environment_id, $token->name]);
        $install = $this->shell->ran[0]['command'];
        $this->assertStringContainsString('systemctl restart buildpusher-log-agent', $install);
        $this->assertSame(1, preg_match("#printf '%s' '([A-Za-z0-9+/=]+)' \\| base64 --decode > /etc/buildpusher/logs.json#", $install, $config));
        $settings = json_decode((string) base64_decode($config[1] ?? ''), true);
        $this->assertSame(route('api.ingest'), $settings['endpoint']);
        $this->assertSame($token->token_hash, hash('sha256', $settings['token']), 'The agent has the key; only its hash is stored.');
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/infrastructure/servers/{$server->id}")->assertOk()->assertJsonPath('logShipping.status', 'active')->assertDontSee($settings['token']);

        $this->shell->reply('', 1, 'python3: not found');
        $this->actingAs($owner)->putJson($url, ['environment_id' => $environment->id])->assertSuccessful();
        $shipping->refresh();
        $this->assertSame(['failed', 'python3: not found'], [$shipping->status, $shipping->last_error]);
        $this->assertNotNull($token->refresh()->revoked_at, 'Reinstalling replaces the key.');
        $this->assertNotSame($token->id, $shipping->ingest_token_id);

        $this->actingAs($owner)->deleteJson($url)->assertSuccessful();
        $this->assertNotNull(IngestToken::query()->findOrFail($shipping->ingest_token_id)->revoked_at);
        $this->assertStringContainsString('systemctl disable --now buildpusher-log-agent', $this->shell->ran[count($this->shell->ran) - 1]['command']);
        $this->assertSame(0, ServerLogShipping::query()->count());
    }
}
