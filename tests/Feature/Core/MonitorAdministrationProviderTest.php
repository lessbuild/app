<?php

namespace Tests\Feature\Core;

use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\AuditLog;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IssueDigestPreference;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use App\Modules\Monitor\Services\Core\MonitorAdministrationContext;
use App\Modules\Monitor\Services\Core\MonitorAlertAdministrationProvider;
use App\Modules\Monitor\Services\Core\MonitorDestinationAdministrationProvider;
use App\Modules\Monitor\Services\Core\MonitorSettingsAdministrationProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Regression coverage authored for Core-backed Monitor administration; intentionally not executed here. */
final class MonitorAdministrationProviderTest extends TestCase
{
    private PlatformUser $actor;

    private CoreWorkspace $workspace;

    private MonitorUser $monitorActor;

    private MonitorWorkspace $monitorWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['core', 'monitor'] as $connection) {
            config(["database.connections.{$connection}.database" => ':memory:']);
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }
        config(['platform.products.monitor.auth_authority' => 'core']);

        $this->actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Core owner', 'email' => 'core-owner@example.test',
            'email_normalized' => 'core-owner@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(), 'owner_user_id' => $this->actor->getKey(),
            'name' => 'Core workspace', 'slug' => 'core-workspace-monitor-admin', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->forceCreate([
            'id' => (string) Str::ulid(), 'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        WorkspaceProductAccess::query()->forceCreate([
            'id' => (string) Str::ulid(), 'membership_id' => $membership->getKey(), 'product' => 'monitor',
            'role' => 'owner', 'status' => 'active', 'granted_at' => now(),
        ]);

        $this->monitorActor = MonitorUser::query()->forceCreate([
            'name' => 'Monitor owner', 'email' => 'monitor-owner@example.test', 'password' => 'hashed', 'email_verified_at' => now(),
        ]);
        $this->monitorWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->monitorActor->getKey(), 'name' => 'Monitor workspace', 'slug' => 'monitor-admin-source', 'plan' => 'free',
        ]);
        $this->monitorWorkspace->members()->attach($this->monitorActor, ['role' => 'owner']);

        $this->map('workspace', (string) $this->monitorWorkspace->getKey(), (string) $this->workspace->getKey());
        $this->map('user', (string) $this->monitorActor->getKey(), (string) $this->actor->getKey());
    }

    public function test_destination_snapshot_redacts_full_endpoint_and_signing_secret(): void
    {
        $secret = 'super-private-signing-key-for-test';
        $destination = AlertDestination::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Private webhook', 'type' => 'webhook',
            'enabled' => true, 'state_version' => 0, 'target_revision' => 0,
            'endpoint_url' => 'https://alerts.example.test/private/path?token=endpoint-secret', 'signing_secret' => $secret,
        ]);

        $snapshot = app(MonitorDestinationAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $serialized = json_encode($snapshot->items->getCollection()->all(), JSON_THROW_ON_ERROR);

        $this->assertTrue($snapshot->available);
        $this->assertSame('alerts.example.test', $snapshot->items->getCollection()->sole()['target']);
        $this->assertStringNotContainsString('private/path', $serialized);
        $this->assertStringNotContainsString('endpoint-secret', $serialized);
        $this->assertStringNotContainsString($secret, $serialized);
        $this->assertArrayNotHasKey('id', $snapshot->items->getCollection()->sole());
        $this->assertArrayNotHasKey('endpoint_url', $snapshot->items->getCollection()->sole());
        $context = app(MonitorAdministrationContext::class);
        $reference = $context->reference('destination', $destination->getKey(), $this->monitorWorkspace);
        $this->assertSame($reference, $context->reference('destination', $destination->getKey(), $this->monitorWorkspace));
    }

    public function test_ambiguous_core_workspace_mapping_fails_closed(): void
    {
        $otherWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->monitorActor->getKey(), 'name' => 'Other Monitor workspace', 'slug' => 'monitor-admin-other', 'plan' => 'free',
        ]);
        $otherWorkspace->members()->attach($this->monitorActor, ['role' => 'owner']);
        $this->map('workspace', (string) $otherWorkspace->getKey(), (string) $this->workspace->getKey());

        $snapshot = app(MonitorDestinationAdministrationProvider::class)->snapshot($this->actor, $this->workspace);

        $this->assertFalse($snapshot->available);
        $this->assertCount(0, $snapshot->items);
    }

    public function test_prepared_deletion_fence_blocks_core_destination_archive(): void
    {
        $destination = AlertDestination::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Fence test', 'type' => 'webhook',
            'enabled' => true, 'state_version' => 0, 'target_revision' => 0,
            'endpoint_url' => 'https://alerts.example.test/events', 'signing_secret' => 'private-test-key',
        ]);
        DB::connection('monitor')->table('product_deletion_fences')->insert([
            'kind' => 'workspace', 'source_id' => (string) $this->monitorWorkspace->getKey(),
            'request_id' => (string) Str::ulid(), 'step_id' => (string) Str::ulid(), 'payload_hash' => hash('sha256', 'fence'),
            'target' => json_encode(['canonicalId' => (string) $this->workspace->getKey()], JSON_THROW_ON_ERROR),
            'status' => 'prepared', 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provider = app(MonitorDestinationAdministrationProvider::class);
        $reference = app(MonitorAdministrationContext::class)
            ->reference('destination', $destination->getKey(), $this->monitorWorkspace);

        try {
            $provider->archiveDestination($this->actor, $this->workspace, $reference, 0);
            $this->fail('A prepared Monitor deletion fence must prevent Core administration mutations.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('alert_destinations', ['id' => $destination->getKey(), 'deleted_at' => null], 'monitor');
    }

    public function test_stale_destination_version_is_rejected_by_existing_monitor_action(): void
    {
        $destination = AlertDestination::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Versioned destination', 'type' => 'webhook',
            'enabled' => true, 'state_version' => 4, 'target_revision' => 2,
            'endpoint_url' => 'https://alerts.example.test/events', 'signing_secret' => 'private-test-key',
        ]);
        $reference = app(MonitorAdministrationContext::class)
            ->reference('destination', $destination->getKey(), $this->monitorWorkspace);

        try {
            app(MonitorDestinationAdministrationProvider::class)
                ->archiveDestination($this->actor, $this->workspace, $reference, 3);
            $this->fail('A stale form must not archive a destination changed by another actor.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('alert_destinations', [
            'id' => $destination->getKey(), 'state_version' => 4, 'enabled' => true, 'deleted_at' => null,
        ], 'monitor');
    }

    public function test_native_members_can_change_only_their_own_digest_preference(): void
    {
        $this->monitorWorkspace->members()->updateExistingPivot($this->monitorActor->getKey(), ['role' => 'member']);
        $other = MonitorUser::query()->forceCreate(['name' => 'Other member', 'email' => 'other-digest@example.test', 'password' => 'hashed', 'email_verified_at' => now()]);
        $this->monitorWorkspace->members()->attach($other, ['role' => 'member']);
        IssueDigestPreference::query()->create(['workspace_id' => $this->monitorWorkspace->getKey(), 'user_id' => $other->getKey(), 'enabled' => true, 'frequency' => 'daily']);

        app(MonitorSettingsAdministrationProvider::class)->saveNotificationPreferences($this->actor, $this->workspace, ['digest_enabled' => false, 'user_id' => $other->getKey()]);

        $this->assertDatabaseHas('issue_digest_preferences', ['workspace_id' => $this->monitorWorkspace->getKey(), 'user_id' => $this->monitorActor->getKey(), 'enabled' => false], 'monitor');
        $this->assertDatabaseHas('issue_digest_preferences', ['workspace_id' => $this->monitorWorkspace->getKey(), 'user_id' => $other->getKey(), 'enabled' => true], 'monitor');
    }

    public function test_signing_key_rotation_reuses_native_action_and_returns_secret_once(): void
    {
        $oldSecret = 'existing-private-signing-key';
        $destination = AlertDestination::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Rotatable destination', 'type' => 'webhook',
            'enabled' => true, 'state_version' => 2, 'target_revision' => 1,
            'endpoint_url' => 'https://alerts.example.test/events', 'signing_secret' => $oldSecret,
        ]);
        $reference = app(MonitorAdministrationContext::class)
            ->reference('destination', $destination->getKey(), $this->monitorWorkspace);

        $result = app(MonitorDestinationAdministrationProvider::class)
            ->rotateDestination($this->actor, $this->workspace, $reference, 2);
        $updated = $destination->fresh();
        $snapshot = app(MonitorDestinationAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $snapshotJson = json_encode($snapshot->items->getCollection()->all(), JSON_THROW_ON_ERROR);

        $secret = $result->takeOneTimeSecret();
        $this->assertNotNull($secret);
        $this->assertNotSame($oldSecret, $secret);
        $this->assertSame($secret, $updated->signing_secret);
        $this->assertSame(3, $updated->state_version);
        $this->assertSame(2, $updated->target_revision);
        $this->assertStringNotContainsString($secret, $snapshotJson);
        $this->assertStringNotContainsString($secret, json_encode($result, JSON_THROW_ON_ERROR));
        $serialized = serialize($result);
        $this->assertStringNotContainsString($secret, $serialized);
        $this->assertNull(unserialize($serialized, ['allowed_classes' => [MonitorMutationResult::class]])->takeOneTimeSecret());
    }

    public function test_audit_snapshot_does_not_query_or_expose_events_without_plan_entitlement(): void
    {
        AuditLog::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'actor_id' => $this->monitorActor->getKey(),
            'action' => 'workspace.updated', 'metadata' => ['label' => 'Private audit entry'],
        ]);

        $snapshot = app(MonitorSettingsAdministrationProvider::class)->auditSnapshot($this->actor, $this->workspace);

        $this->assertFalse($snapshot->settings['audit_available']);
        $this->assertCount(0, $snapshot->items);
    }

    public function test_alert_rules_are_searchable_and_paginated_without_losing_page_state(): void
    {
        $application = Application::factory()->create(['workspace_id' => $this->monitorWorkspace->getKey()]);
        $environment = Environment::factory()->create(['application_id' => $application->getKey()]);
        foreach (range(1, 21) as $index) {
            AlertRule::factory()->create(['environment_id' => $environment->getKey(), 'name' => 'Rule '.$index]);
        }

        $provider = app(MonitorAlertAdministrationProvider::class);
        $first = $provider->snapshot($this->actor, $this->workspace);
        $second = $provider->snapshot($this->actor, $this->workspace, ['rules_page' => 2]);
        $searched = $provider->snapshot($this->actor, $this->workspace, ['rules_search' => 'Rule 21']);

        $this->assertSame(21, $first->items->total());
        $this->assertCount(20, $first->items->items());
        $this->assertSame(2, $second->items->currentPage());
        $this->assertCount(1, $second->items->items());
        $this->assertSame('Rule 21', $searched->items->items()[0]['name']);
    }

    public function test_destination_and_verified_recipient_pickers_are_searchable_beyond_the_first_page(): void
    {
        $application = Application::factory()->create(['workspace_id' => $this->monitorWorkspace->getKey()]);
        $environment = Environment::factory()->create(['application_id' => $application->getKey()]);
        foreach (range(1, 31) as $index) {
            AlertDestination::query()->forceCreate([
                'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Target '.$index, 'type' => 'webhook',
                'enabled' => true, 'state_version' => 0, 'target_revision' => 0,
                'endpoint_url' => 'https://alerts.example.test/'.$index, 'signing_secret' => 'private-test-key-'.$index,
            ]);
        }
        $lastDestination = AlertDestination::query()->where('name', 'Target 31')->firstOrFail();
        $rule = AlertRule::factory()->create(['environment_id' => $environment->getKey()]);
        $rule->destinations()->attach($lastDestination->getKey());
        foreach (range(1, 30) as $index) {
            $recipient = MonitorUser::query()->forceCreate([
                'name' => 'Recipient '.$index, 'email' => 'recipient-'.$index.'@example.test',
                'password' => 'hashed', 'email_verified_at' => now(),
            ]);
            $this->monitorWorkspace->members()->attach($recipient, ['role' => 'member']);
        }

        $provider = app(MonitorDestinationAdministrationProvider::class);
        $secondPage = $provider->snapshot($this->actor, $this->workspace, ['destination_page' => 2]);
        $searched = $provider->snapshot($this->actor, $this->workspace, ['destination_search' => 'Target 31']);
        $recipientPage = $provider->snapshot($this->actor, $this->workspace, ['recipient_page' => 2]);
        $selectedOutsidePage = $provider->snapshot($this->actor, $this->workspace, ['destination_page' => 1]);

        $this->assertSame(31, $secondPage->items->total());
        $this->assertCount(11, $secondPage->items->items());
        $this->assertSame('Target 31', $searched->items->items()[0]['name']);
        $this->assertSame(31, $recipientPage->settings['recipients']->total());
        $this->assertCount(6, $recipientPage->settings['recipients']->items());
        $this->assertContains('Target 31', collect($selectedOutsidePage->settings['destinations'])->pluck('name')->all());
    }

    public function test_environment_series_and_objective_pickers_page_and_keep_existing_rule_values(): void
    {
        $application = Application::factory()->create(['workspace_id' => $this->monitorWorkspace->getKey()]);
        $environments = collect(range(1, 26))->map(fn (int $index) => Environment::factory()->create([
            'application_id' => $application->getKey(), 'name' => 'Environment '.$index, 'slug' => 'environment-'.$index,
        ]));
        $environment = $environments->last();
        collect(range(1, 26))->each(fn (int $index) => MetricSeries::query()->forceCreate([
            'environment_id' => $environment->getKey(), 'identity_hash' => hash('sha256', 'series-'.$index),
            'source' => 'otlp', 'name' => 'Series '.$index, 'resource_label' => 'service-'.$index,
            'unit' => '1', 'kind' => 'gauge', 'temporality' => null, 'monotonic' => false, 'descriptor' => [],
            'first_received_at' => now(), 'last_received_at' => now(),
        ]));
        $objectives = collect(range(1, 26))->map(fn (int $index) => ServiceLevelObjective::query()->forceCreate([
            'environment_id' => $environment->getKey(), 'name' => 'Objective '.$index, 'indicator' => 'availability',
            'service' => null, 'route' => null, 'target' => 0.990, 'window_days' => 30, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $rule = AlertRule::factory()->create([
            'environment_id' => $environment->getKey(), 'metric' => 'slo_burn_rate',
            'service_level_objective_id' => $objectives->last()->getKey(),
        ]);
        $snapshot = app(MonitorAlertAdministrationProvider::class)->snapshot($this->actor, $this->workspace, [
            'environment_page' => 2, 'series_page' => 2, 'objective_page' => 2,
        ]);
        $item = $snapshot->items->items()[0];

        $this->assertSame(26, $snapshot->settings['environments']->total());
        $this->assertSame(26, $snapshot->settings['metric_series']->total());
        $this->assertSame(26, $snapshot->settings['objectives']->total());
        $this->assertContains($item['environment_reference'], collect($snapshot->settings['environments'])->pluck('reference')->all());
        $this->assertContains($item['objective_reference'], collect($snapshot->settings['objectives'])->pluck('reference')->all());
        $this->assertSame('slo_burn_rate', $item['metric']);
        $this->assertSame(2, $snapshot->settings['metric_series']->currentPage());
        $this->assertSame(2, $snapshot->settings['objectives']->currentPage());
        $this->assertSame((string) $rule->getKey(), app(MonitorAdministrationContext::class)->sourceId($item['reference'], 'rule', $this->monitorWorkspace));
    }

    public function test_entitled_audit_history_uses_bounded_searchable_pages(): void
    {
        $this->monitorWorkspace->forceFill(['plan' => 'pro'])->save();
        foreach (range(1, 31) as $index) {
            AuditLog::query()->forceCreate([
                'workspace_id' => $this->monitorWorkspace->getKey(), 'actor_id' => $this->monitorActor->getKey(),
                'action' => $index === 31 ? 'invitation.sent' : 'workspace.updated',
                'metadata' => ['label' => 'Audit record '.$index],
            ]);
        }

        $provider = app(MonitorSettingsAdministrationProvider::class);
        $secondPage = $provider->auditSnapshot($this->actor, $this->workspace, ['audit_page' => 2]);
        $searched = $provider->auditSnapshot($this->actor, $this->workspace, ['audit_search' => 'invitation.sent']);

        $this->assertTrue($secondPage->settings['audit_available']);
        $this->assertSame(31, $secondPage->items->total());
        $this->assertCount(1, $secondPage->items->items());
        $this->assertSame(1, $searched->items->total());
    }

    private function map(string $entity, string $sourceId, string $canonicalId): void
    {
        LegacyIdentityMap::query()->forceCreate([
            'id' => (string) Str::ulid(), 'source_product' => 'monitor', 'source_entity' => $entity,
            'source_id' => $sourceId, 'canonical_entity' => $entity, 'canonical_id' => $canonicalId,
            'status' => 'reconciled',
        ]);
    }
}
