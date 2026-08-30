<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RepositoryWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_enable_rotate_and_disable_a_github_webhook_with_a_one_time_secret(): void
    {
        [$owner, $repository] = $this->repository(Provider::TYPE_GITHUB);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('repositories.webhook.store', $repository))
            ->assertForbidden();

        $response = $this->actingAs($owner)
            ->post(route('repositories.webhook.store', $repository));
        $repository->refresh();
        $secret = $repository->webhook_secret;

        $response
            ->assertRedirect(route('repositories.show', $repository).'#deployment-webhook')
            ->assertSessionHas("repository:{$repository->id}:webhook_secret", $secret);
        $this->assertTrue($repository->webhook_enabled);
        $this->assertSame(64, strlen($secret));
        $this->assertNotSame($secret, DB::table('repositories')->where('id', $repository->id)->value('webhook_secret'));
        $this->assertArrayNotHasKey('webhook_secret', $repository->toArray());

        $this->actingAs($owner)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertSee(route('webhooks.repositories.receive', $repository))
            ->assertSee($secret)
            ->assertSee('Copy this webhook secret now');
        $this->actingAs($owner)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertDontSee($secret);

        $this->actingAs($owner)->post(route('repositories.webhook.store', $repository));
        $this->assertNotSame($secret, $repository->fresh()->webhook_secret);

        $repository->update(['webhook_pending' => true]);
        $this->actingAs($owner)
            ->delete(route('repositories.webhook.destroy', $repository))
            ->assertRedirect(route('repositories.show', $repository).'#deployment-webhook');
        $repository->refresh();
        $this->assertFalse($repository->webhook_enabled);
        $this->assertFalse($repository->webhook_pending);
        $this->assertNull($repository->webhook_secret);

        $this->send($repository, ['ref' => 'refs/heads/main'], [])->assertNotFound();
    }

    public function test_github_push_is_authenticated_branch_filtered_and_replay_safe(): void
    {
        Queue::fake();
        [, $repository] = $this->repository(Provider::TYPE_GITHUB);
        $secret = $this->enable($repository);
        $payload = ['ref' => 'refs/heads/main'];

        $this->send($repository, $payload, [
            'X-Hub-Signature-256' => 'sha256='.str_repeat('0', 64),
            'X-GitHub-Delivery' => 'github-invalid',
            'X-GitHub-Event' => 'push',
        ])->assertUnauthorized();

        $wrongBranch = ['ref' => 'refs/heads/develop'];
        $this->send($repository, $wrongBranch, $this->githubHeaders($secret, $wrongBranch, 'github-wrong'))
            ->assertOk()
            ->assertJson(['status' => 'branch_ignored']);
        $this->assertDatabaseCount('builds', 0);

        $ping = ['zen' => 'Keep it logically awesome.'];
        $this->send($repository, $ping, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $this->raw($ping), $secret),
            'X-GitHub-Delivery' => 'github-ping',
            'X-GitHub-Event' => 'ping',
        ])->assertOk()->assertJson(['status' => 'event_ignored']);

        $headers = $this->githubHeaders($secret, $payload, 'github-1');
        $this->send($repository, $payload, $headers)
            ->assertStatus(202)
            ->assertJson(['status' => 'queued']);
        $this->send($repository, $payload, $headers)
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $build = $repository->builds()->sole();
        $this->assertSame(Build::STATUS_QUEUED, $build->status);
        $this->assertDatabaseHas('repository_webhook_deliveries', [
            'repository_id' => $repository->id,
            'delivery_id' => 'github-1',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
        ]);
        Queue::assertPushed(PublishRepositoryJob::class, 1);
    }

    public function test_bitbucket_push_uses_raw_body_hmac_and_matches_changed_branch(): void
    {
        Queue::fake();
        [, $repository] = $this->repository(Provider::TYPE_BITBUCKET);
        $secret = $this->enable($repository);
        $payload = ['push' => ['changes' => [
            ['new' => ['type' => 'tag', 'name' => 'main']],
            ['new' => ['type' => 'branch', 'name' => 'main']],
        ]]];
        $raw = $this->raw($payload);

        $this->send($repository, $payload, [
            'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $raw, $secret),
            'X-Request-UUID' => '{bitbucket-request-1}',
            'X-Event-Key' => 'repo:push',
        ])->assertStatus(202)->assertJson(['status' => 'queued']);

        Queue::assertPushed(PublishRepositoryJob::class, 1);
        $this->assertDatabaseHas('repository_webhook_deliveries', [
            'delivery_id' => '{bitbucket-request-1}',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
        ]);
    }

    public function test_gitlab_signing_token_requires_current_hmac_timestamp(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->repository(Provider::TYPE_GITLAB);
        $secret = 'whsec_'.base64_encode(random_bytes(32));

        $this->actingAs($owner)->post(route('repositories.webhook.store', $repository), [
            'signing_token' => 'not-a-signing-token',
        ])->assertSessionHasErrors('signing_token');
        $this->actingAs($owner)->post(route('repositories.webhook.store', $repository), [
            'signing_token' => $secret,
        ])->assertRedirect();
        $this->assertSame($secret, $repository->fresh()->webhook_secret);
        $this->assertNotSame($secret, DB::table('repositories')->where('id', $repository->id)->value('webhook_secret'));

        $payload = ['ref' => 'refs/heads/main'];
        $headers = $this->gitLabHeaders($secret, $payload, 'gitlab-1', now()->getTimestamp());
        $this->send($repository, $payload, $headers)
            ->assertStatus(202)
            ->assertJson(['status' => 'queued']);

        $expired = $this->gitLabHeaders($secret, $payload, 'gitlab-expired', now()->subMinutes(6)->getTimestamp());
        $this->send($repository, $payload, $expired)->assertUnauthorized();
        $this->send($repository, $payload, [
            'X-Gitlab-Token' => $secret,
            'X-Gitlab-Event' => 'Push Hook',
        ])->assertUnauthorized();
        Queue::assertPushed(PublishRepositoryJob::class, 1);
    }

    public function test_pushes_during_deployment_coalesce_into_one_follow_up_build(): void
    {
        Queue::fake();
        [, $repository] = $this->repository(Provider::TYPE_GITHUB);
        $secret = $this->enable($repository);
        $active = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
        $payload = ['ref' => 'refs/heads/main'];

        foreach (['github-pending-1', 'github-pending-2'] as $deliveryId) {
            $this->send($repository, $payload, $this->githubHeaders($secret, $payload, $deliveryId))
                ->assertStatus(202)
                ->assertJson(['status' => 'pending']);
        }

        $this->assertTrue($repository->fresh()->webhook_pending);
        $this->assertCount(1, $repository->builds);
        Queue::assertNothingPushed();

        $active->update(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()]);

        $this->assertFalse($repository->fresh()->webhook_pending);
        $this->assertCount(2, $repository->fresh()->builds);
        $this->assertSame(2, $repository->webhookDeliveries()
            ->where('status', RepositoryWebhookDelivery::STATUS_QUEUED)
            ->count());
        $queued = $repository->builds()->where('status', Build::STATUS_QUEUED)->sole();
        Queue::assertPushed(PublishRepositoryJob::class, fn (PublishRepositoryJob $job): bool => $job->build->is($queued));
    }

    public function test_valid_push_reports_unavailable_infrastructure_without_queuing(): void
    {
        Queue::fake();
        [, $repository] = $this->repository(Provider::TYPE_GITHUB);
        $secret = $this->enable($repository);
        $repository->website->update(['provisioning_status' => Website::STATUS_FAILED]);
        $payload = ['ref' => 'refs/heads/main'];

        $this->send($repository, $payload, $this->githubHeaders($secret, $payload, 'github-unavailable'))
            ->assertStatus(409)
            ->assertJson(['status' => 'unavailable']);

        $this->assertDatabaseCount('builds', 0);
        $this->assertDatabaseHas('repository_webhook_deliveries', [
            'delivery_id' => 'github-unavailable',
            'status' => RepositoryWebhookDelivery::STATUS_UNAVAILABLE,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_enabled_webhook_rejects_oversized_payload_before_processing(): void
    {
        [, $repository] = $this->repository(Provider::TYPE_GITHUB);
        $this->enable($repository);
        config(['lessbuild.webhook_max_payload_bytes' => 16]);

        $this->send($repository, ['ref' => 'refs/heads/main'], [])
            ->assertStatus(413)
            ->assertJson(['status' => 'payload_too_large']);

        $this->assertDatabaseCount('repository_webhook_deliveries', 0);
        $this->assertDatabaseCount('builds', 0);
    }

    /** @return array{User, Repository} */
    private function repository(string $providerType): array
    {
        $owner = User::factory()->create();
        $source = $owner->providers()->create([
            'name' => ucfirst($providerType),
            'provider' => $providerType,
            'token' => 'provider-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $source->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => $source->repositoryHost().'/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$owner, $repository];
    }

    private function enable(Repository $repository): string
    {
        $secret = 'webhook-secret-'.str_repeat('x', 48);
        $repository->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);

        return $secret;
    }

    /** @return array<string, string> */
    private function githubHeaders(string $secret, array $payload, string $deliveryId): array
    {
        return [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $this->raw($payload), $secret),
            'X-GitHub-Delivery' => $deliveryId,
            'X-GitHub-Event' => 'push',
        ];
    }

    /** @return array<string, string> */
    private function gitLabHeaders(string $secret, array $payload, string $deliveryId, int $timestamp): array
    {
        $key = base64_decode(substr($secret, 6), true);
        $signature = base64_encode(hash_hmac(
            'sha256',
            $deliveryId.'.'.$timestamp.'.'.$this->raw($payload),
            $key,
            true,
        ));

        return [
            'webhook-id' => $deliveryId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1,'.$signature,
            'X-Gitlab-Event' => 'Push Hook',
        ];
    }

    private function send(Repository $repository, array $payload, array $headers): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call(
            'POST',
            route('webhooks.repositories.receive', $repository),
            server: $server,
            content: $this->raw($payload),
        );
    }

    private function raw(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
