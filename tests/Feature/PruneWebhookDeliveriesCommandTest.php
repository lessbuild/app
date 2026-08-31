<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PruneWebhookDeliveriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prunes_only_expired_completed_delivery_history(): void
    {
        $repository = $this->repository();
        $terminalBuild = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $activeBuild = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
        $old = now()->subDays(91);

        $expired = collect([
            $this->delivery($repository, 'old-unavailable', RepositoryWebhookDelivery::STATUS_UNAVAILABLE, $old),
            $this->delivery($repository, 'old-superseded', RepositoryWebhookDelivery::STATUS_SUPERSEDED, $old),
            $this->delivery($repository, 'old-terminal-build', RepositoryWebhookDelivery::STATUS_QUEUED, $old, $terminalBuild),
            $this->delivery($repository, 'old-missing-build', RepositoryWebhookDelivery::STATUS_QUEUED, $old),
        ]);
        $preserved = collect([
            $this->delivery($repository, 'old-pending', RepositoryWebhookDelivery::STATUS_PENDING, $old),
            $this->delivery($repository, 'old-received', RepositoryWebhookDelivery::STATUS_RECEIVED, $old),
            $this->delivery($repository, 'old-active-build', RepositoryWebhookDelivery::STATUS_QUEUED, $old, $activeBuild),
            $this->delivery($repository, 'recent-unavailable', RepositoryWebhookDelivery::STATUS_UNAVAILABLE, now()->subDays(89)),
        ]);

        $this->assertSame(0, Artisan::call('lessbuild:webhooks:prune'));
        $this->assertStringContainsString('Pruned 4 webhook delivery record(s) older than 90 day(s).', Artisan::output());
        $expired->each(fn (RepositoryWebhookDelivery $delivery) => $this->assertModelMissing($delivery));
        $preserved->each(fn (RepositoryWebhookDelivery $delivery) => $this->assertModelExists($delivery));
    }

    public function test_command_supports_an_explicit_retention_window(): void
    {
        $repository = $this->repository();
        $expired = $this->delivery(
            $repository,
            'custom-expired',
            RepositoryWebhookDelivery::STATUS_SUPERSEDED,
            now()->subDays(31),
        );
        $recent = $this->delivery(
            $repository,
            'custom-recent',
            RepositoryWebhookDelivery::STATUS_SUPERSEDED,
            now()->subDays(29),
        );

        $this->assertSame(0, Artisan::call('lessbuild:webhooks:prune', ['--days' => '30']));
        $this->assertModelMissing($expired);
        $this->assertModelExists($recent);
    }

    public function test_command_rejects_invalid_retention_without_deleting_history(): void
    {
        $repository = $this->repository();
        $delivery = $this->delivery(
            $repository,
            'must-remain',
            RepositoryWebhookDelivery::STATUS_SUPERSEDED,
            now()->subYear(),
        );

        foreach (['0', '-1', '1.5', 'invalid'] as $days) {
            $this->assertSame(1, Artisan::call('lessbuild:webhooks:prune', ['--days' => $days]));
            $this->assertStringContainsString('Retention days must be a positive integer.', Artisan::output());
            $this->assertModelExists($delivery);
        }
    }

    private function repository(): Repository
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
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

        return $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);
    }

    private function delivery(
        Repository $repository,
        string $deliveryId,
        string $status,
        mixed $createdAt,
        ?Build $build = null,
    ): RepositoryWebhookDelivery {
        return $repository->webhookDeliveries()->create([
            'delivery_id' => $deliveryId,
            'status' => $status,
            'build_id' => $build?->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
