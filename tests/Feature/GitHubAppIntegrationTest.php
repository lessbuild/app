<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GitHubAppIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'github-app.id' => '12345',
            'github-app.slug' => 'buildpusher-test',
            'github-app.private_key' => $this->privateKey(),
            'github-app.webhook_secret' => 'github-app-secret',
        ]);
    }

    public function test_installation_callback_discovers_repositories_and_creates_app_provider(): void
    {
        Http::fakeSequence()
            ->push(['token' => 'ghs_installation_token'], 201)
            ->push(['repositories' => [[
                'id' => 99, 'full_name' => 'buildpusher/example', 'private' => true, 'default_branch' => 'main',
            ]]], 200);
        $user = User::factory()->create();

        $connect = $this->actingAs($user)->get(route('github-app.connect'))->assertRedirect();
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get(route('github-app.callback', [
            'installation_id' => 777,
            'setup_action' => 'install',
            'state' => $query['state'],
        ]))->assertRedirect()->assertSessionHas('success');

        $provider = Provider::query()->sole();
        $this->assertTrue($provider->isGitHubApp());
        $this->assertSame('777', $provider->external_id);
        $this->assertSame(Provider::CONNECTION_HEALTHY, $provider->connection_status);
        Http::assertSentCount(2);
    }

    public function test_app_repository_is_auto_subscribed_and_signed_push_queues_deployment(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        $provider = $user->workspaceProviders()->create([
            'user_id' => $user->id, 'name' => 'GitHub App', 'description' => 'Installation', 'provider' => Provider::TYPE_GITHUB,
            'credential_type' => 'app', 'external_id' => '777', 'token' => 'placeholder',
        ]);
        $server = $user->workspaceServers()->create([
            'user_id' => $user->id, 'name' => 'server', 'display_name' => 'Server', 'region' => 'test', 'image' => 'ubuntu', 'size' => 'small',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->workspaceWebsites()->create([
            'user_id' => $user->id, 'server_id' => $server->id, 'name' => 'App', 'description' => 'App', 'environment' => '',
            'url' => 'app.example.test', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->post(route('repositories.store'), [
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Example',
            'url' => 'github.com/buildpusher/example.git', 'branch' => 'main', 'description' => 'Example app',
        ])->assertRedirect();
        $repository = $user->workspaceRepositories()->sole();
        $this->assertTrue($repository->webhook_enabled);
        $this->assertSame('github-app-secret', $repository->webhook_secret);

        $payload = json_encode([
            'installation' => ['id' => 777],
            'repository' => ['full_name' => 'buildpusher/example'],
            'ref' => 'refs/heads/main', 'after' => str_repeat('a', 40), 'deleted' => false,
            'head_commit' => ['message' => 'Ship it'],
        ], JSON_THROW_ON_ERROR);
        $this->call('POST', route('github-app.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'delivery-1',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'github-app-secret'),
        ], $payload)->assertAccepted()->assertJson(['status' => 'queued']);

        $this->assertDatabaseHas('builds', ['repository_id' => $repository->id, 'status' => Build::STATUS_QUEUED]);
    }

    private function privateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);

        return $privateKey;
    }
}
