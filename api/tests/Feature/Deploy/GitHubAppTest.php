<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\ProviderConnectionTester;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class GitHubAppTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $private);
        $this->publicKey = (string) (openssl_pkey_get_details($key)['key'] ?? '');
        config(['github-app.id' => '12345', 'github-app.slug' => 'buildpusher', 'github-app.webhook_secret' => 'app-webhook-secret', 'github-app.private_key' => $private]);
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_installation_token'], 201),
            'api.github.com/installation/repositories*' => Http::response(['repositories' => [['id' => 1, 'full_name' => 'Acme/Shop', 'private' => true, 'default_branch' => 'trunk']]]),
        ]);
        $this->project = Project::factory()->withServices(['deploy'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Installing the app adds a github provider after a matching state.
     */
    public function test_installing_the_app_adds_a_github_provider_after_a_matching_state(): void
    {
        $this->actingAs($this->owner)->getJson('/api/app/account/providers')->assertJsonPath('githubApp', true);
        $redirect = $this->actingAs($this->owner)->get('/github-app/connect')->assertRedirect();
        $this->assertStringStartsWith('https://github.com/apps/buildpusher/installations/new?state=', (string) $redirect->headers->get('Location'));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';

        $this->actingAs($this->owner)->get('/github-app/callback?installation_id=77&state='.str_repeat('x', 64))->assertForbidden();
        $this->actingAs($this->owner)->get('/github-app/connect');
        $this->assertSame(0, Provider::query()->count());

        // The state from the second visit is the one that counts.
        $second = $this->actingAs($this->owner)->get('/github-app/connect');
        parse_str((string) parse_url((string) $second->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->actingAs($this->owner)->get('/github-app/callback?installation_id=77&setup_action=install&state='.(is_string($query['state'] ?? null) ? $query['state'] : ''))->assertRedirect();
        $provider = Provider::query()->sole();
        $this->assertSame([ProviderType::GitHub, 'app', '77', 'GitHub App · Acme', true], [$provider->type, $provider->credential_type, $provider->external_id, $provider->name, $provider->isGitHubApp()]);
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/access_tokens')) {
                return false;
            }
            [$header, $claims, $signature] = explode('.', substr($request->header('Authorization')[0] ?? '', 7));

            return openssl_verify("{$header}.{$claims}", base64_decode(strtr($signature, '-_', '+/'), true) ?: '', $this->publicKey, OPENSSL_ALGO_SHA256) === 1
                && json_decode((string) base64_decode(strtr($claims, '-_', '+/'), true), true)['iss'] === '12345';
        });

        $this->actingAs($this->owner)->getJson("/api/app/github-app/providers/{$provider->id}/repositories")->assertOk()->assertJsonPath('repositories.0.full_name', 'Acme/Shop')
            ->assertJsonPath('projects.0.id', $this->project->id);
        $this->assertTrue(app(ProviderConnectionTester::class)->test($provider)['successful']);

        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $this->actingAs($member)->get('/github-app/connect')->assertForbidden();
        $this->assertNotSame('', $state);
    }

    /**
     * App repositories clone with installation tokens and deploy from the app webhook.
     */
    public function test_app_repositories_clone_with_installation_tokens_and_deploy_from_the_app_webhook(): void
    {
        $provider = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'credential_type' => 'app', 'external_id' => '77', 'token' => 'github-app-installation']);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id]);
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories", [
            'name' => 'shop', 'provider_id' => $provider->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'website_id' => $website->id,
        ])->assertSuccessful();
        $repository = Repository::query()->sole();
        $this->assertSame([true, 'app-webhook-secret'], [$repository->webhook_enabled, $repository->webhook_secret]);

        $this->push('{"zen":"ok"}', 'ping', 'app-webhook-secret')->assertOk()->assertJson(['status' => 'ok']);
        $body = json_encode(['ref' => 'refs/heads/main', 'after' => str_repeat('a', 40), 'head_commit' => ['message' => 'Ship'], 'installation' => ['id' => 77], 'repository' => ['full_name' => 'Acme/Shop']], JSON_THROW_ON_ERROR);
        $this->push($body, 'push', 'wrong')->assertUnauthorized();
        $other = json_encode(['ref' => 'refs/heads/main', 'installation' => ['id' => 78], 'repository' => ['full_name' => 'Acme/Shop']], JSON_THROW_ON_ERROR);
        $this->push($other, 'push', 'app-webhook-secret')->assertNotFound();
        $this->push($body, 'push', 'app-webhook-secret')->assertStatus(202)->assertJson(['status' => 'queued']);

        $build = Build::query()->sole();
        $this->assertSame([Build::STATUS_RUNNING, str_repeat('a', 40)], [$build->status, $build->revision]);
        $this->assertStringContainsString(base64_encode("machine github.com\nlogin x-access-token\npassword ghs_installation_token\n"), $this->scripts->started[0]['script']);
    }

    /** @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function push(string $body, string $event, string $secret): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/github-app/webhook', [], [], [], [
            'HTTP_X_GITHUB_EVENT' => $event, 'HTTP_X_GITHUB_DELIVERY' => 'delivery-'.md5($body.$event), 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }
}
