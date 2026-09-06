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
            'created_at' => '2026-08-20 12:00:00',
            'updated_at' => '2026-08-20 12:00:00',
        ]);
        $repository->webhookDeliveries()->create([
            'delivery_id' => 'hidden-pending-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_PENDING,
            'created_at' => '2026-08-20 11:00:00',
            'updated_at' => '2026-08-20 11:00:00',
        ]);
        $repository->webhookDeliveries()->create([
            'delivery_id' => 'before-window-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'created_at' => '2026-08-19 23:59:59',
            'updated_at' => '2026-08-19 23:59:59',
        ]);
        $repository->webhookDeliveries()->create([
            'delivery_id' => 'after-window-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'created_at' => '2026-08-21 00:00:00',
            'updated_at' => '2026-08-21 00:00:00',
        ]);
        $foreignRepository->webhookDeliveries()->create([
            'delivery_id' => 'foreign-private-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('repositories.show', [
            $repository,
            'delivery_status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'delivery_date_from' => '2026-08-20',
            'delivery_date_to' => '2026-08-20',
        ]));

        $response
            ->assertSuccessful()
            ->assertViewHas('deliveryMetrics', [
                'total' => 1,
                'queued' => 1,
                'pending' => 0,
                'unavailable' => 0,
                'superseded' => 0,
                'received' => 0,
            ])
            ->assertSee('Webhook delivery history')
            ->assertSee('visible-queued-delivery')
            ->assertSee(substr($revision, 0, 12))
            ->assertSee("https://github.com/example/application/commit/{$revision}")
            ->assertSee(route('builds.show', $build))
            ->assertSee('&lt;script&gt;alert(&quot;delivery&quot;)&lt;/script&gt;', false)
            ->assertDontSee($message, false)
            ->assertDontSee('hidden-pending-delivery')
            ->assertDontSee('before-window-delivery')
            ->assertDontSee('after-window-delivery')
            ->assertDontSee('foreign-private-delivery')
            ->assertDontSee('provider-secret')
            ->assertDontSee('webhook-secret');
    }

    public function test_owner_sees_all_delivery_state_counts_and_explicit_empty_metrics(): void
    {
        [$owner, $repository] = $this->repository();
        foreach (RepositoryWebhookDelivery::STATUSES as $index => $status) {
            $repository->webhookDeliveries()->create([
                'delivery_id' => "delivery-{$status}",
                'status' => $status,
                'created_at' => "2026-08-20 12:0{$index}:00",
                'updated_at' => "2026-08-20 12:0{$index}:00",
            ]);
        }

        $this->actingAs($owner)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertViewHas('deliveryMetrics', [
                'total' => 5,
                'queued' => 1,
                'pending' => 1,
                'unavailable' => 1,
                'superseded' => 1,
                'received' => 1,
            ])
            ->assertSee('Matching deliveries')
            ->assertSee('Queued deliveries')
            ->assertSee('Pending deliveries')
            ->assertSee('Unavailable deliveries')
            ->assertSee('Superseded deliveries')
            ->assertSee('Received deliveries')
            ->assertDontSee('provider-secret')
            ->assertDontSee('webhook-secret');

        $this->actingAs($owner)->get(route('repositories.show', [
            $repository,
            'delivery_date_from' => '2026-08-21',
        ]))
            ->assertSuccessful()
            ->assertViewHas('deliveryMetrics', [
                'total' => 0,
                'queued' => 0,
                'pending' => 0,
                'unavailable' => 0,
                'superseded' => 0,
                'received' => 0,
            ])
            ->assertSee('No webhook deliveries match these filters.');
    }

    public function test_delivery_history_is_paginated_and_preserves_a_valid_filter(): void
    {
        [$owner, $repository] = $this->repository();

        foreach (range(1, 11) as $number) {
            $repository->webhookDeliveries()->create([
                'delivery_id' => sprintf('pending-delivery-%02d', $number),
                'status' => RepositoryWebhookDelivery::STATUS_PENDING,
                'created_at' => '2026-08-20 12:00:00',
                'updated_at' => '2026-08-20 12:00:00',
            ]);
        }

        $this->actingAs($owner)->get(route('repositories.show', [
            $repository,
            'delivery_status' => RepositoryWebhookDelivery::STATUS_PENDING,
            'delivery_date_from' => '2026-08-20',
            'delivery_date_to' => '2026-08-20',
        ]))
            ->assertSuccessful()
            ->assertSee('webhook_page=2', false)
            ->assertSee('delivery_status=pending', false)
            ->assertSee('delivery_date_from=2026-08-20', false)
            ->assertSee('delivery_date_to=2026-08-20', false)
            ->assertSee('pending-delivery-11')
            ->assertDontSee('pending-delivery-01');

        $this->actingAs($owner)->get(route('repositories.show', [
            $repository,
            'delivery_status' => 'not-a-status',
            'delivery_date_from' => '2026-02-31',
            'delivery_date_to' => '../../etc/passwd',
        ]))
            ->assertSuccessful()
            ->assertViewHas('deliveryFilters', [
                'delivery_status' => null,
                'delivery_date_from' => null,
                'delivery_date_to' => null,
            ])
            ->assertSee('pending-delivery-11')
            ->assertDontSee('not-a-status', false);
    }

    public function test_filtered_delivery_export_is_owner_scoped_and_spreadsheet_safe(): void
    {
        [$owner, $repository] = $this->repository();
        [$intruder, $foreignRepository] = $this->repository();
        $build = $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
        ]);
        $delivery = $repository->webhookDeliveries()->create([
            'delivery_id' => '=spreadsheet-delivery',
            'revision' => '-spreadsheet-revision',
            'commit_message' => " \t@spreadsheet-message",
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'build_id' => $build->id,
            'created_at' => '2026-08-20 12:00:00',
            'updated_at' => '2026-08-20 12:00:00',
        ]);
        $repository->webhookDeliveries()->create([
            'delivery_id' => 'filtered-pending-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_PENDING,
            'created_at' => '2026-08-20 11:00:00',
            'updated_at' => '2026-08-20 11:00:00',
        ]);
        $repository->webhookDeliveries()->create([
            'delivery_id' => 'before-window-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'created_at' => '2026-08-19 23:59:59',
            'updated_at' => '2026-08-19 23:59:59',
        ]);
        $repository->webhookDeliveries()->create([
            'delivery_id' => 'after-window-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'created_at' => '2026-08-21 00:00:00',
            'updated_at' => '2026-08-21 00:00:00',
        ]);
        $foreignRepository->webhookDeliveries()->create([
            'delivery_id' => 'foreign-private-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('repositories.webhook-deliveries.export', [
            $repository,
            'delivery_status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            'delivery_date_from' => '2026-08-20',
            'delivery_date_to' => '2026-08-20',
        ]));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            "attachment; filename=lessbuild-repository-{$repository->id}-webhook-deliveries-",
            (string) $response->headers->get('content-disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('filtered-pending-delivery', $content);
        $this->assertStringNotContainsString('before-window-delivery', $content);
        $this->assertStringNotContainsString('after-window-delivery', $content);
        $this->assertStringNotContainsString('foreign-private-delivery', $content);
        $this->assertStringNotContainsString('provider-secret', $content);
        $this->assertStringNotContainsString('webhook-secret', $content);
        $rows = $this->csvRows($content);
        $this->assertSame([
            'Delivery ID',
            'Status',
            'Revision',
            'Commit message',
            'Build ID',
            'Build status',
            'Received at',
            'Updated at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame("'=spreadsheet-delivery", $rows[1][0]);
        $this->assertSame(RepositoryWebhookDelivery::STATUS_QUEUED, $rows[1][1]);
        $this->assertSame("'-spreadsheet-revision", $rows[1][2]);
        $this->assertSame("' \t@spreadsheet-message", $rows[1][3]);
        $this->assertSame((string) $delivery->build_id, $rows[1][4]);
        $this->assertSame(Build::STATUS_FAILED, $rows[1][5]);

        $this->actingAs($intruder)
            ->get(route('repositories.webhook-deliveries.export', $repository))
            ->assertForbidden();
        auth()->logout();
        $this->get(route('repositories.webhook-deliveries.export', $repository))
            ->assertRedirect(route('login'));
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

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
