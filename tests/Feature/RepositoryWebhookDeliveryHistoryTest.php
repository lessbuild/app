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
use Tests\TestCase;

class RepositoryWebhookDeliveryHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_filter_safe_delivery_history_with_build_links(): void
    {
        [$owner, $repository] = $this->repository();
        [, $foreignRepository] = $this->repository();
        $revision = str_repeat('a', 40);
        $message = '<script>alert("delivery")</script>';
        $build = $repository->builds()->create([
            'status' => Build::STATUS_QUEUED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'revision' => $revision,
        ]);

        $repository->webhookDeliveries()->create([
            'delivery_id' => 'visible-queued-delivery',
            'revision' => $revision,
            'commit_message' => $message,
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'build_id' => $build->id,
        ]);
        $repository->webhookDeliveries()->create([
            'delivery_id' => 'hidden-pending-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_PENDING,
        ]);
        $foreignRepository->webhookDeliveries()->create([
            'delivery_id' => 'foreign-private-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
        ]);

        $response = $this->actingAs($owner)->get(route('repositories.show', [
            $repository,
            'delivery_status' => RepositoryWebhookDelivery::STATUS_QUEUED,
        ]));

        $response
            ->assertSuccessful()
            ->assertSee('Webhook delivery history')
            ->assertSee('visible-queued-delivery')
            ->assertSee(substr($revision, 0, 12))
            ->assertSee("https://github.com/example/application/commit/{$revision}")
            ->assertSee(route('builds.show', $build))
            ->assertSee('&lt;script&gt;alert(&quot;delivery&quot;)&lt;/script&gt;', false)
            ->assertDontSee($message, false)
            ->assertDontSee('hidden-pending-delivery')
            ->assertDontSee('foreign-private-delivery')
            ->assertDontSee('provider-secret')
            ->assertDontSee('webhook-secret');
    }

    public function test_delivery_history_is_paginated_and_preserves_a_valid_filter(): void
    {
        [$owner, $repository] = $this->repository();

        foreach (range(1, 11) as $number) {
            $repository->webhookDeliveries()->create([
                'delivery_id' => sprintf('pending-delivery-%02d', $number),
                'status' => RepositoryWebhookDelivery::STATUS_PENDING,
            ]);
        }

        $this->actingAs($owner)->get(route('repositories.show', [
            $repository,
            'delivery_status' => RepositoryWebhookDelivery::STATUS_PENDING,
        ]))
            ->assertSuccessful()
            ->assertSee('webhook_page=2', false)
            ->assertSee('delivery_status=pending', false)
            ->assertSee('pending-delivery-11')
            ->assertDontSee('pending-delivery-01');

        $this->actingAs($owner)->get(route('repositories.show', [
            $repository,
            'delivery_status' => 'not-a-status',
        ]))
            ->assertSuccessful()
            ->assertSee('pending-delivery-11')
            ->assertDontSee('not-a-status', false);
    }

    /** @return array{User, Repository} */
    private function repository(): array
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
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
            'webhook_secret' => 'webhook-secret',
        ]);

        return [$owner, $repository];
    }
}
