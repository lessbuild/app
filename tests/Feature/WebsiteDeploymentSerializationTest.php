<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Jobs\Web\AddWebsiteJob;
use App\Models\Build;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebsiteDeploymentSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_deployments_are_serialized_per_website_but_not_per_server(): void
    {
        Queue::fake();
        [$owner, $first, $second, $separate] = $this->repositories();
        $active = $first->builds()->create(['status' => Build::STATUS_RUNNING]);

        $this->actingAs($owner)->post(route('repositories.deploy', $second))
            ->assertSessionHas('info', 'A deployment is already in progress');
        $this->assertCount(0, $second->builds);

        $this->actingAs($owner)->get(route('repositories.show', $second))
            ->assertSuccessful()
            ->assertSee('Deployment in progress')
            ->assertSee('disabled', false);

        $this->actingAs($owner)->post(route('repositories.deploy', $separate))
            ->assertSessionHas('success', 'Deployment queued');
        $this->assertCount(1, $separate->builds);
        Queue::assertPushed(PublishRepositoryJob::class, fn (PublishRepositoryJob $job): bool => $job->build->repository->is($separate));
        $this->assertSame(Build::STATUS_RUNNING, $active->fresh()->status);
    }

    public function test_cross_repository_pushes_queue_oldest_first_and_finish_with_the_newest_revision(): void
    {
        Queue::fake();
        [, $first, $second, $third] = $this->repositories(sameWebsite: true);
        $active = $first->builds()->create(['status' => Build::STATUS_RUNNING]);
        $secret = 'webhook-secret-'.str_repeat('x', 48);
        $second->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $third->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $secondRevision = str_repeat('b', 40);
        $thirdRevision = str_repeat('c', 40);

        $this->push($second, $secret, 'second-push', $secondRevision)
            ->assertAccepted()
            ->assertJson(['status' => 'pending']);
        $this->travel(1)->minute();
        $this->push($third, $secret, 'third-push', $thirdRevision)
            ->assertAccepted()
            ->assertJson(['status' => 'pending']);

        $active->update(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()]);
        $secondBuild = $second->builds()->where('status', Build::STATUS_QUEUED)->sole();
        $this->assertSame($secondRevision, $secondBuild->revision);
        $this->assertTrue($third->fresh()->webhook_pending);
        $this->assertDatabaseHas('repository_webhook_deliveries', [
            'repository_id' => $second->id,
            'delivery_id' => 'second-push',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('repository_webhook_deliveries', [
            'repository_id' => $third->id,
            'delivery_id' => 'third-push',
            'status' => RepositoryWebhookDelivery::STATUS_PENDING,
        ]);

        $secondBuild->update(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()]);
        $thirdBuild = $third->builds()->where('status', Build::STATUS_QUEUED)->sole();
        $this->assertSame($thirdRevision, $thirdBuild->revision);
        $this->assertFalse($third->fresh()->webhook_pending);
        Queue::assertPushed(PublishRepositoryJob::class, 2);
    }

    public function test_redeployment_is_blocked_by_another_repository_on_the_website(): void
    {
        Queue::fake();
        [$owner, $first, $second] = $this->repositories();
        $first->builds()->create(['status' => Build::STATUS_RUNNING]);
        $source = $second->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('d', 40),
            'finished_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('builds.redeploy', $source))
            ->assertSessionHas('info', 'A deployment is already in progress.');

        $this->assertCount(1, $second->builds);
        Queue::assertNothingPushed();
    }

    public function test_website_and_repository_cannot_change_or_be_deleted_during_deployment(): void
    {
        Queue::fake();
        [$owner, $first, $second] = $this->repositories();
        $website = $first->website;
        $first->builds()->create(['status' => Build::STATUS_RUNNING]);

        $this->actingAs($owner)->patch(route('repositories.update', $second), [
            'provider_id' => $second->provider_id,
            'website_id' => $website->id,
            'name' => $second->name,
            'url' => $second->url,
            'branch' => $second->branch,
            'description' => $second->description,
        ])->assertSessionHasErrors([
            'website_id' => 'Wait for the current website deployment to finish before editing this repository.',
        ]);
        $this->actingAs($owner)->delete(route('repositories.destroy', $second))
            ->assertSessionHas('error', 'Wait for the current website deployment to finish before deleting this repository.');

        $this->actingAs($owner)->patch(route('websites.update', $website), [
            'server_id' => $website->server_id,
            'name' => $website->name,
            'url' => $website->url,
            'description' => $website->description,
            'environment' => $website->environment,
            'health_check_enabled' => '0',
            'health_check_path' => '/',
        ])->assertSessionHasErrors([
            'server_id' => 'Wait for the current website deployment to finish before editing this website.',
        ]);
        $this->actingAs($owner)->delete(route('websites.destroy', $website))
            ->assertSessionHas('error', 'Wait for the current deployment to finish before deleting this website.');

        $this->assertFalse($second->fresh()->trashed());
        $this->assertFalse($website->fresh()->trashed());
        Queue::assertNotPushed(AddWebsiteJob::class);
    }

    /** @return array{User, Repository, Repository, Repository} */
    private function repositories(bool $sameWebsite = false): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);
        $website = $this->website($owner, $server, 'Application');
        $separateWebsite = $sameWebsite ? $website : $this->website($owner, $server, 'Marketing');

        return [
            $owner,
            $this->repository($owner, $provider, $website, 'First'),
            $this->repository($owner, $provider, $website, 'Second'),
            $this->repository($owner, $provider, $separateWebsite, 'Third'),
        ];
    }

    private function website(User $owner, Server $server, string $name): Website
    {
        return $owner->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'description' => "{$name} website",
            'environment' => 'APP_ENV=production',
            'url' => strtolower($name).'.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
    }

    private function repository(
        User $owner,
        Provider $provider,
        Website $website,
        string $name,
    ): Repository {
        return $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => "{$name} repository",
            'url' => 'github.com/example/'.strtolower($name).'.git',
            'branch' => 'main',
            'description' => "{$name} source",
        ]);
    }

    private function push(
        Repository $repository,
        string $secret,
        string $deliveryId,
        string $revision,
    ): TestResponse {
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => $revision,
            'head_commit' => ['message' => "Deploy {$deliveryId}"],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->call(
            'POST',
            route('webhooks.repositories.receive', $repository),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
                'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
                'HTTP_X_GITHUB_EVENT' => 'push',
            ],
            content: $raw,
        );
    }
}
