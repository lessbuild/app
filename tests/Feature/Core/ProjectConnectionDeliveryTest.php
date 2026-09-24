<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\Workspace;
use App\Core\Services\Connections\DispatchDeploymentSucceededOutboxEvent;
use App\Core\Services\Connections\DispatchMonitorIncidentOutboxEvent;
use App\Core\Services\Connections\ProcessProjectConnectionDelivery;
use App\Core\Services\Connections\ProjectConnectionDiagnostics;
use App\Core\Services\Connections\RetryProjectConnectionDelivery;
use App\Core\Services\Projects\ProjectWorkflowProgress;
use App\Core\Services\Projects\SetProjectConnectionAutomationState;
use App\Modules\Analytics\Models\SiteIncidentAnnotation;
use App\Modules\Analytics\Models\SiteReleaseAnnotation;
use App\Modules\Analytics\Services\Connections\ConsumeDeployerReleaseAnnotation;
use App\Modules\Analytics\Services\Connections\ConsumeMonitorIncidentAnnotation;
use App\Modules\Deployer\Models\DeploymentSucceededOutboxEvent;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\ProjectConnectionEventReceipt;
use App\Modules\Monitor\Models\ProjectConnectionIncidentOutboxEvent;
use App\Modules\Monitor\Services\Connections\ConsumeDeploymentSucceeded;
use App\Modules\Monitor\Services\Connections\RecordProjectConnectionIncidentOutboxEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ProjectConnectionDeliveryTest extends TestCase
{
    private string $workspaceId;

    private string $projectId;

    private string $sourceResourceId;

    private string $targetResourceId;

    private string $analyticsResourceId;

    private string $sourceEnvironmentId;

    private string $targetEnvironmentId;

    private string $connectionId;

    private string $ownerUserId;

    private int $deployerEnvironmentId;

    private int $monitorEnvironmentId;

    private int $analyticsSiteId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'platform.products.monitor.enabled' => true,
            'platform.products.analytics.enabled' => true,
        ]);
        $this->bindEntitledPlans();
        $this->createCoreTables();
        $this->createDeployerTables();
        $this->createMonitorTables();
        $this->createAnalyticsTables();
        $this->seedConnectedResources();
    }

    protected function tearDown(): void
    {
        foreach ([
            'project_connection_deliveries', 'project_connection_events', 'project_connections', 'project_resources', 'project_environments',
            'project_products', 'project_memberships', 'workspace_product_access', 'workspace_memberships',
            'projects', 'workspaces', 'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        Schema::dropIfExists('deployment_succeeded_outbox_events');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('builds');

        foreach ([
            'project_connection_incident_outbox_events', 'project_connection_event_receipts', 'deployments', 'releases', 'environments', 'applications', 'workspaces',
        ] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        Schema::connection('analytics')->dropIfExists('site_incident_annotations');
        Schema::connection('analytics')->dropIfExists('site_release_annotations');
        Schema::connection('analytics')->dropIfExists('sites');

        parent::tearDown();
    }

    public function test_deployer_outbox_delivers_a_deployment_to_monitor_once_and_keeps_a_receipt(): void
    {
        $event = $this->outboxEvent();
        $created = app(DispatchDeploymentSucceededOutboxEvent::class)->dispatch($event);

        $this->assertSame(1, $created);
        $delivery = ProjectConnectionDelivery::query()->sole();
        $this->assertSame($event->getKey(), $delivery->source_event_id);
        $this->assertSame((string) $this->monitorEnvironmentId, $delivery->payload['target_environment_id']);
        $this->assertSame((string) $this->deployerEnvironmentId, $delivery->payload['source_environment_id']);

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());
        $deployment = Deployment::query()->sole();

        $this->assertSame('delivered', $status);
        $this->assertSame('integration', $deployment->source);
        $this->assertSame($this->monitorEnvironmentId, $deployment->environment_id);
        $this->assertSame(str_repeat('c', 40), $deployment->commit_sha);
        $this->assertSame(str_repeat('c', 40), $deployment->release->version);
        $this->assertSame(1, ProjectConnectionEventReceipt::query()->count());
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('active', ProjectConnection::query()->findOrFail($this->connectionId)->status);

        $replayed = app(ConsumeDeploymentSucceeded::class)->handle(
            deliveryId: (string) $delivery->getKey(),
            connectionId: $this->connectionId,
            payload: $delivery->payload,
        );

        $this->assertSame($deployment->getKey(), $replayed->getKey());
        $this->assertSame(1, Deployment::query()->count());
        $this->assertSame(1, ProjectConnectionEventReceipt::query()->count());
    }

    public function test_delivery_reconciliation_previews_then_repairs_only_a_missing_record_idempotently(): void
    {
        $event = $this->outboxEvent();
        $dispatcher = app(DispatchDeploymentSucceededOutboxEvent::class);
        $this->assertSame(1, $dispatcher->dispatch($event));
        ProjectConnectionDelivery::query()->sole()->delete();
        $event->forceFill(['status' => 'dispatched'])->save();
        $this->assertSame('dispatched', $event->fresh()->status);
        $this->assertSame(1, DeploymentSucceededOutboxEvent::query()
            ->whereKey($event->getKey())
            ->whereIn('status', ['dispatched', 'failed'])
            ->count());

        $arguments = ['--source' => 'deployer', '--event-id' => (string) $event->getKey()];
        $this->artisan('project-connections:reconcile', $arguments)
            ->expectsOutputToContain('1 missing')
            ->assertExitCode(0);
        $this->assertSame(0, ProjectConnectionDelivery::query()->count());

        $this->artisan('project-connections:reconcile', [...$arguments, '--apply' => true])
            ->expectsOutputToContain('1 created')
            ->assertExitCode(0);
        $delivery = ProjectConnectionDelivery::query()->sole();
        $this->assertSame('pending', $delivery->status);

        $this->artisan('project-connections:reconcile', [...$arguments, '--apply' => true])
            ->expectsOutputToContain('0 created')
            ->assertExitCode(0);
        $this->assertSame(1, ProjectConnectionDelivery::query()->count());
    }

    public function test_deployer_reconciliation_does_not_backfill_a_connection_created_after_the_event(): void
    {
        $event = $this->outboxEvent();
        ProjectConnection::query()->whereKey($this->connectionId)->update([
            'created_at' => $event->created_at->copy()->addSecond(),
        ]);
        $event->forceFill(['status' => 'dispatched'])->save();

        $this->assertSame(0, app(DispatchDeploymentSucceededOutboxEvent::class)->missingDeliveryCount($event));
        $this->artisan('project-connections:reconcile', [
            '--source' => 'deployer',
            '--event-id' => (string) $event->getKey(),
        ])->expectsOutputToContain('0 missing')->assertExitCode(0);
        $this->assertSame(0, ProjectConnectionDelivery::query()->count());
    }

    public function test_slow_delivery_worker_cannot_overwrite_a_recovered_claim(): void
    {
        $delivery = $this->makeDelivery();
        $reclaimed = false;

        DB::listen(function (QueryExecuted $query) use ($delivery, &$reclaimed): void {
            if ($reclaimed || $query->connectionName !== 'monitor'
                || ! str_contains($query->sql, 'project_connection_event_receipts')) {
                return;
            }

            $reclaimed = true;
            DB::connection('core')->table('project_connection_deliveries')
                ->where('id', $delivery->getKey())
                ->update([
                    'status' => 'processing',
                    'attempts' => 2,
                    'last_attempted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());

        $this->assertTrue($reclaimed);
        $this->assertSame('skipped', $status);
        $this->assertSame('processing', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(1, Deployment::query()->count());
        $this->assertSame(1, ProjectConnectionEventReceipt::query()->count());
    }

    public function test_slow_source_dispatcher_cannot_complete_a_recovered_outbox_claim(): void
    {
        $event = $this->outboxEvent();
        $reclaimed = false;

        DB::listen(function (QueryExecuted $query) use ($event, &$reclaimed): void {
            if ($reclaimed || $query->connectionName !== 'core'
                || ! str_contains($query->sql, 'project_resources')) {
                return;
            }

            $reclaimed = true;
            DeploymentSucceededOutboxEvent::query()
                ->whereKey($event->getKey())
                ->update([
                    'status' => 'processing',
                    'attempts' => 2,
                    'updated_at' => now(),
                ]);
        });

        $exitCode = Artisan::call('project-connections:deliver');

        $this->assertSame(0, $exitCode);
        $this->assertTrue($reclaimed);
        $this->assertSame('processing', $event->fresh()->status);
        $this->assertSame(2, $event->fresh()->attempts);
        $this->assertSame(1, ProjectConnectionDelivery::query()
            ->where('source_event_id', $event->getKey())
            ->count());
    }

    public function test_target_outage_keeps_the_source_success_and_retries_only_the_pending_delivery(): void
    {
        $analyticsConnectionId = (string) Str::ulid();
        DB::connection('core')->table('project_connections')->insert([
            'id' => $analyticsConnectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $this->sourceResourceId,
            'target_resource_id' => $this->analyticsResourceId,
            'source_environment_id' => $this->sourceEnvironmentId,
            'target_environment_id' => null,
            'capabilities' => json_encode(['release_annotations'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_by_user_id' => null,
            'last_succeeded_at' => null,
            'last_error_code' => null,
            'last_error_at' => null,
            'disconnected_at' => null,
            'automation_paused_at' => null,
            'metadata' => null,
            'created_at' => now()->subSecond(),
            'updated_at' => now(),
        ]);
        $event = $this->outboxEvent();
        $this->assertSame(2, app(DispatchDeploymentSucceededOutboxEvent::class)->dispatch($event));
        $event->forceFill(['status' => 'dispatched'])->save();
        $delivery = ProjectConnectionDelivery::query()
            ->where('project_connection_id', $analyticsConnectionId)
            ->sole();
        $failureInjected = false;
        DB::listen(function (QueryExecuted $query) use (&$failureInjected): void {
            if ($failureInjected || $query->connectionName !== 'analytics'
                || ! str_contains(strtolower($query->sql), 'from "sites"')) {
                return;
            }

            $failureInjected = true;
            throw new RuntimeException('Simulated Analytics database outage');
        });

        $this->assertSame('pending', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
        $this->assertSame('succeeded', DB::table('builds')->where('id', $event->source_build_id)->value('status'));
        $this->assertSame('dispatched', $event->fresh()->status);
        $this->assertTrue($failureInjected);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame('target_delivery_failed', $delivery->fresh()->last_error_code);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertNotNull($delivery->fresh()->available_at);
        $this->assertSame(0, SiteReleaseAnnotation::query()->count());

        $delivery->forceFill(['available_at' => now()->subMinute()])->save();
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertTrue($delivery->fresh()->available_at->lte(now()));
        $this->assertSame('delivered', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
        $this->assertSame('succeeded', DB::table('builds')->where('id', $event->source_build_id)->value('status'));
        $this->assertSame('dispatched', $event->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(1, SiteReleaseAnnotation::query()->count());
    }

    public function test_deployments_without_an_active_core_mapping_are_consumed_without_retries(): void
    {
        $event = $this->outboxEvent();
        DB::connection('core')->table('project_resources')->where('id', $this->sourceResourceId)->update([
            'status' => 'inactive',
        ]);

        $created = app(DispatchDeploymentSucceededOutboxEvent::class)->dispatch($event);

        $this->assertSame(0, $created);
        $this->assertSame(0, ProjectConnectionDelivery::query()->count());
    }

    public function test_deployer_deployments_annotate_analytics_sites_once_after_an_owner_retries_revoked_access(): void
    {
        $analyticsConnectionId = (string) Str::ulid();
        DB::connection('core')->table('project_connections')->insert([
            'id' => $analyticsConnectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $this->sourceResourceId,
            'target_resource_id' => $this->analyticsResourceId,
            'source_environment_id' => $this->sourceEnvironmentId,
            'target_environment_id' => null,
            'capabilities' => json_encode(['release_annotations'], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'created_by_user_id' => null,
            'last_succeeded_at' => null,
            'last_error_code' => null,
            'last_error_at' => null,
            'disconnected_at' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event = $this->outboxEvent();

        $this->assertSame(2, app(DispatchDeploymentSucceededOutboxEvent::class)->dispatch($event));
        $connections = ProjectConnection::query()
            ->where('project_id', $this->projectId)
            ->with(['sourceResource', 'targetResource'])
            ->get();
        $workflow = app(ProjectWorkflowProgress::class)->forProject(
            Workspace::query()->findOrFail($this->workspaceId),
            Project::query()->findOrFail($this->projectId),
            $connections,
            canManageConnections: true,
        )->sole();
        $this->assertCount(3, $workflow->steps);
        $this->assertSame(ProjectWorkflowStepState::Succeeded, $workflow->steps[0]->state);
        $this->assertSame(ProjectWorkflowStepState::Pending, $workflow->steps[1]->state);
        $this->assertSame(ProjectWorkflowStepState::Pending, $workflow->steps[2]->state);
        $workflowHtml = Blade::render('<x-signal.ui.workflow-run :run="$run" />', ['run' => $workflow]);
        $this->assertStringContainsString('Deployment', $workflowHtml);
        $this->assertStringNotContainsString((string) $event->getKey(), $workflowHtml);

        $delivery = ProjectConnectionDelivery::query()
            ->where('project_connection_id', $analyticsConnectionId)
            ->sole();
        $this->assertSame((string) $this->analyticsSiteId, $delivery->payload['target_site_id']);
        $this->assertArrayNotHasKey('target_environment_id', $delivery->payload);

        DB::connection('core')->table('workspace_product_access')->where('product', 'analytics')->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);
        $this->assertSame('blocked', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
        $this->assertSame(0, SiteReleaseAnnotation::query()->count());
        $workflow = app(ProjectWorkflowProgress::class)->forProject(
            Workspace::query()->findOrFail($this->workspaceId),
            Project::query()->findOrFail($this->projectId),
            ProjectConnection::query()->where('project_id', $this->projectId)->with(['sourceResource', 'targetResource'])->get(),
            canManageConnections: true,
        )->sole();
        $this->assertSame(ProjectWorkflowStepState::Succeeded, $workflow->steps[0]->state);
        $analyticsStep = collect($workflow->steps)->firstWhere('product', 'analytics');
        $this->assertSame(ProjectWorkflowStepState::Blocked, $analyticsStep->state);
        $workflowHtml = Blade::render('<x-signal.ui.workflow-run :run="$run" />', ['run' => $workflow]);
        $this->assertStringNotContainsString('connection_authorization_failed', $workflowHtml);

        DB::connection('core')->table('workspace_product_access')->where('product', 'analytics')->update([
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $retried = app(RetryProjectConnectionDelivery::class)->handle(
            PlatformUser::query()->findOrFail($this->ownerUserId),
            Project::query()->findOrFail($this->projectId),
            ProjectConnection::query()->findOrFail($analyticsConnectionId),
            $delivery,
        );

        $this->assertTrue($retried);
        $this->assertSame('delivered', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
        $annotation = SiteReleaseAnnotation::query()->sole();
        $this->assertSame($this->analyticsSiteId, $annotation->site_id);
        $this->assertSame($delivery->payload['deployment_id'], $annotation->deployment_id);
        $this->assertSame(str_repeat('c', 40), $annotation->revision);
        $this->assertSame(str_repeat('c', 40), $annotation->version);
        $this->assertSame('delivered', $delivery->fresh()->status);

        $replayed = app(ConsumeDeployerReleaseAnnotation::class)->handle(
            deliveryId: (string) $delivery->getKey(),
            connectionId: $analyticsConnectionId,
            payload: $delivery->payload,
        );

        $this->assertSame($annotation->getKey(), $replayed->getKey());
        $this->assertSame(1, SiteReleaseAnnotation::query()->count());
    }

    public function test_monitor_incident_lifecycle_is_delivered_to_analytics_as_a_minimal_timeline(): void
    {
        $analyticsConnectionId = (string) Str::ulid();
        DB::connection('core')->table('project_connections')->insert([
            'id' => $analyticsConnectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $this->targetResourceId,
            'target_resource_id' => $this->analyticsResourceId,
            'source_environment_id' => $this->targetEnvironmentId,
            'target_environment_id' => null,
            'capabilities' => json_encode(['incident_annotations'], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'created_by_user_id' => null,
            'last_succeeded_at' => null,
            'last_error_code' => null,
            'last_error_at' => null,
            'disconnected_at' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dispatcher = app(DispatchMonitorIncidentOutboxEvent::class);
        $events = [
            [ProjectConnectionIncidentOutboxEvent::OPENED, 'open'],
            [ProjectConnectionIncidentOutboxEvent::ACKNOWLEDGED, 'acknowledged'],
            [ProjectConnectionIncidentOutboxEvent::RESOLVED, 'resolved'],
        ];
        $deliveries = collect();

        foreach ($events as $index => [$eventType, $status]) {
            $event = ProjectConnectionIncidentOutboxEvent::query()->create([
                'event_type' => $eventType,
                'event_version' => 1,
                'source_incident_id' => '44',
                'source_environment_id' => (string) $this->monitorEnvironmentId,
                'payload' => [
                    'incident_id' => '44',
                    'status' => $status,
                    'occurred_at' => now()->subMinutes(3 - $index)->utc()->toIso8601String(),
                ],
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
            ]);

            $this->assertSame(1, $dispatcher->dispatch($event));
            $this->assertSame(0, $dispatcher->dispatch($event));
            $delivery = ProjectConnectionDelivery::query()
                ->where('project_connection_id', $analyticsConnectionId)
                ->where('source_event_id', $event->getKey())
                ->sole();
            $this->assertArrayNotHasKey('title', $delivery->payload);
            $this->assertArrayNotHasKey('observation', $delivery->payload);
            $this->assertSame($status, $delivery->payload['status']);
            $this->assertSame('delivered', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
            $deliveries->push($delivery);
        }

        $this->assertSame(['resolved', 'acknowledged', 'open'], SiteIncidentAnnotation::query()->orderByDesc('occurred_at')->pluck('status')->all());
        $this->assertSame(3, SiteIncidentAnnotation::query()->count());
        $this->assertSame($this->analyticsSiteId, SiteIncidentAnnotation::query()->firstOrFail()->site_id);

        $firstDelivery = $deliveries->first();
        $annotation = SiteIncidentAnnotation::query()->where('delivery_id', $firstDelivery->getKey())->sole();
        $replayed = app(ConsumeMonitorIncidentAnnotation::class)->handle(
            deliveryId: (string) $firstDelivery->getKey(),
            connectionId: $analyticsConnectionId,
            payload: $firstDelivery->payload,
        );

        $this->assertSame($annotation->getKey(), $replayed->getKey());
        $this->assertSame(3, SiteIncidentAnnotation::query()->count());
    }

    public function test_monitor_incident_reconciliation_does_not_backfill_a_connection_created_after_the_event(): void
    {
        $event = ProjectConnectionIncidentOutboxEvent::query()->create([
            'event_type' => ProjectConnectionIncidentOutboxEvent::OPENED,
            'event_version' => 1,
            'source_incident_id' => '44',
            'source_environment_id' => (string) $this->monitorEnvironmentId,
            'payload' => [
                'incident_id' => '44',
                'status' => 'open',
                'occurred_at' => now()->utc()->toIso8601String(),
            ],
            'status' => 'dispatched',
            'attempts' => 1,
            'available_at' => null,
        ]);
        $connectionId = (string) Str::ulid();
        DB::connection('core')->table('project_connections')->insert([
            'id' => $connectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $this->targetResourceId,
            'target_resource_id' => $this->analyticsResourceId,
            'source_environment_id' => $this->targetEnvironmentId,
            'target_environment_id' => null,
            'capabilities' => json_encode(['incident_annotations'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_by_user_id' => null,
            'last_succeeded_at' => null,
            'last_error_code' => null,
            'last_error_at' => null,
            'disconnected_at' => null,
            'automation_paused_at' => null,
            'metadata' => null,
            'created_at' => $event->created_at->copy()->addSecond(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, app(DispatchMonitorIncidentOutboxEvent::class)->missingDeliveryCount($event));
        $this->artisan('project-connections:reconcile', [
            '--source' => 'monitor',
            '--event-id' => (string) $event->getKey(),
        ])->expectsOutputToContain('0 missing')->assertExitCode(0);
        $this->assertSame(0, ProjectConnectionDelivery::query()->count());
    }

    public function test_monitor_reconciliation_previews_then_repairs_a_missing_record_idempotently(): void
    {
        $connectionId = (string) Str::ulid();
        DB::connection('core')->table('project_connections')->insert([
            'id' => $connectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $this->targetResourceId,
            'target_resource_id' => $this->analyticsResourceId,
            'source_environment_id' => $this->targetEnvironmentId,
            'target_environment_id' => null,
            'capabilities' => json_encode(['incident_annotations'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_by_user_id' => null,
            'last_succeeded_at' => null,
            'last_error_code' => null,
            'last_error_at' => null,
            'disconnected_at' => null,
            'automation_paused_at' => null,
            'metadata' => null,
            'created_at' => now()->subSecond(),
            'updated_at' => now(),
        ]);
        $event = ProjectConnectionIncidentOutboxEvent::query()->create([
            'event_type' => ProjectConnectionIncidentOutboxEvent::OPENED,
            'event_version' => 1,
            'source_incident_id' => '44',
            'source_environment_id' => (string) $this->monitorEnvironmentId,
            'payload' => [
                'incident_id' => '44',
                'status' => 'open',
                'occurred_at' => now()->utc()->toIso8601String(),
            ],
            'status' => 'dispatched',
            'attempts' => 1,
            'available_at' => null,
        ]);
        $dispatcher = app(DispatchMonitorIncidentOutboxEvent::class);
        $this->assertSame(1, $dispatcher->dispatch($event));
        ProjectConnectionDelivery::query()->sole()->delete();

        $arguments = ['--source' => 'monitor', '--event-id' => (string) $event->getKey()];
        $this->artisan('project-connections:reconcile', $arguments)
            ->expectsOutputToContain('1 missing')
            ->assertExitCode(0);
        $this->assertSame(0, ProjectConnectionDelivery::query()->count());

        $this->artisan('project-connections:reconcile', [...$arguments, '--apply' => true])
            ->expectsOutputToContain('1 created')
            ->assertExitCode(0);
        $delivery = ProjectConnectionDelivery::query()->sole();
        $this->assertSame('pending', $delivery->status);

        $this->artisan('project-connections:reconcile', [...$arguments, '--apply' => true])
            ->expectsOutputToContain('0 created')
            ->assertExitCode(0);
        $this->assertSame(1, ProjectConnectionDelivery::query()->count());
    }

    public function test_monitor_incident_outbox_records_once_inside_the_source_transaction(): void
    {
        $incident = new class extends Incident
        {
            public function source(): AlertRule|Monitor|null
            {
                return (new AlertRule)->forceFill(['environment_id' => 321]);
            }
        };
        $incident->forceFill(['id' => 45, 'opened_at' => now()]);

        $eventId = DB::connection('monitor')->transaction(function () use ($incident): string {
            $producer = app(RecordProjectConnectionIncidentOutboxEvent::class);
            $event = $producer->record($incident, 'opened');
            $replayed = $producer->record($incident, 'opened');

            $this->assertNotNull($event);
            $this->assertNotNull($replayed);
            $this->assertSame($event->getKey(), $replayed->getKey());

            return (string) $event->getKey();
        });

        $event = ProjectConnectionIncidentOutboxEvent::query()->findOrFail($eventId);
        $this->assertSame('monitor.incident_opened', $event->event_type);
        $this->assertSame('321', $event->source_environment_id);
        $this->assertSame('open', $event->payload['status']);
        $this->assertSame('45', $event->payload['incident_id']);
    }

    public function test_disconnected_connections_discard_queued_deliveries_without_writing_monitor_data(): void
    {
        $delivery = $this->makeDelivery();
        ProjectConnection::query()->whereKey($this->connectionId)->update([
            'status' => 'disconnected',
            'disconnected_at' => now(),
        ]);

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());

        $this->assertSame('discarded', $status);
        $this->assertSame('connection_disconnected', $delivery->fresh()->last_error_code);
        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(0, ProjectConnectionEventReceipt::query()->count());
    }

    public function test_revoked_product_access_blocks_a_delivery_until_an_owner_retries_it(): void
    {
        $delivery = $this->makeDelivery();
        DB::connection('core')->table('workspace_product_access')->where('product', 'monitor')->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());

        $this->assertSame('blocked', $status);
        $this->assertSame('product_access_changed', $delivery->fresh()->last_error_code);
        $this->assertSame('failed', ProjectConnection::query()->findOrFail($this->connectionId)->status);
        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(0, ProjectConnectionEventReceipt::query()->count());

        $diagnostic = app(ProjectConnectionDiagnostics::class)->forConnection(
            ProjectConnection::query()->with('deliveries')->findOrFail($this->connectionId),
        );
        $this->assertSame('Access changed', $diagnostic->status);
        $this->assertStringNotContainsString('product_access_changed', $diagnostic->detail);

        DB::connection('core')->table('workspace_product_access')->where('product', 'monitor')->update([
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $retried = app(RetryProjectConnectionDelivery::class)->handle(
            PlatformUser::query()->findOrFail($this->ownerUserId),
            Project::query()->findOrFail($this->projectId),
            ProjectConnection::query()->findOrFail($this->connectionId),
            $delivery,
        );

        $this->assertTrue($retried);
        $this->assertSame('delivered', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
        $this->assertSame(1, Deployment::query()->count());
        $this->assertSame(1, ProjectConnectionEventReceipt::query()->count());
    }

    public function test_unavailable_product_subscription_has_a_distinct_safe_delivery_diagnostic(): void
    {
        $delivery = $this->makeDelivery();

        app()->instance(ProductPlanResolver::class, new class implements ProductPlanResolver
        {
            public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
            {
                if ($product === ProductKey::Monitor) {
                    return ProductPlanResolution::unavailable($product, $workspaceId, 'current_subscription_missing');
                }

                return new ProductPlanResolution(
                    product: $product,
                    workspaceId: $workspaceId,
                    available: true,
                    planKey: 'test',
                    subscriptionStatus: 'active',
                    entitlements: ['*'],
                    limits: ['deployment_context_minutes' => 60],
                );
            }
        });

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());

        $this->assertSame('blocked', $status);
        $this->assertSame('product_subscription_unavailable', $delivery->fresh()->last_error_code);
        $this->assertSame(0, Deployment::query()->count());

        $diagnostic = app(ProjectConnectionDiagnostics::class)->forConnection(
            ProjectConnection::query()->with('deliveries')->findOrFail($this->connectionId),
        );
        $html = Blade::render('<x-signal.ui.project-connection-diagnostic :diagnostic="$diagnostic" />', [
            'diagnostic' => $diagnostic,
        ]);

        $this->assertSame('Subscription needs attention', $diagnostic->status);
        $this->assertStringContainsString('Review the subscription for each connected app', $html);
        $this->assertStringNotContainsString('current_subscription_missing', $html);
    }

    public function test_plan_feature_and_configured_limit_failures_keep_distinct_reason_codes(): void
    {
        foreach ([
            ['feature', 'product_feature_not_included'],
            ['limit', 'product_limit_unavailable'],
        ] as [$restriction, $expectedReason]) {
            app()->instance(ProductPlanResolver::class, $this->restrictedPlans($restriction));
            $delivery = $this->makeDelivery();

            $this->assertSame('blocked', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
            $this->assertSame($expectedReason, $delivery->fresh()->last_error_code);
            $this->assertSame(0, Deployment::query()->count());
        }
    }

    public function test_retry_resets_only_the_selected_failed_workflow_step(): void
    {
        $selected = $this->makeDelivery();
        $other = $this->makeDelivery();
        $selected->forceFill(['status' => 'blocked', 'attempts' => 4, 'last_error_code' => 'connection_authorization_failed'])->save();
        $other->forceFill(['status' => 'failed', 'attempts' => 12, 'last_error_code' => 'target_delivery_failed'])->save();

        $retried = app(RetryProjectConnectionDelivery::class)->handle(
            PlatformUser::query()->findOrFail($this->ownerUserId),
            Project::query()->findOrFail($this->projectId),
            ProjectConnection::query()->findOrFail($this->connectionId),
            $selected,
        );

        $this->assertTrue($retried);
        $this->assertSame('pending', $selected->fresh()->status);
        $this->assertSame(0, $selected->fresh()->attempts);
        $this->assertSame('failed', $other->fresh()->status);
        $this->assertSame(12, $other->fresh()->attempts);
    }

    public function test_paused_automation_holds_queued_steps_and_resume_keeps_the_connection_history(): void
    {
        $delivery = $this->makeDelivery();
        $user = PlatformUser::query()->findOrFail($this->ownerUserId);
        $project = Project::query()->findOrFail($this->projectId);
        $connection = ProjectConnection::query()->findOrFail($this->connectionId);
        $automation = app(SetProjectConnectionAutomationState::class);

        $automation->handle($user, $project, $connection, paused: true);
        $this->assertNotNull($connection->fresh()->automation_paused_at);
        $this->assertSame('pending', $connection->fresh()->status);
        $this->assertNull($connection->fresh()->disconnected_at);
        $this->assertSame('paused', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
        $this->assertSame(0, Deployment::query()->count());

        $automation->handle($user, $project, $connection, paused: false);

        $this->assertNull($connection->fresh()->automation_paused_at);
        $this->assertSame([
            'automation_paused',
            'automation_resumed',
        ], DB::connection('core')->table('project_connection_events')->orderBy('occurred_at')->pluck('event_type')->all());
        $this->assertSame('delivered', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_pausing_after_a_delivery_is_claimed_blocks_the_step_without_failing_the_connection(): void
    {
        $delivery = $this->makeDelivery();
        $user = PlatformUser::query()->findOrFail($this->ownerUserId);
        $project = Project::query()->findOrFail($this->projectId);
        $connection = ProjectConnection::query()->findOrFail($this->connectionId);
        $paused = false;

        DB::listen(function (QueryExecuted $query) use ($user, $project, $connection, &$paused): void {
            if ($paused || $query->connectionName !== 'monitor'
                || ! str_contains($query->sql, 'project_connection_event_receipts')) {
                return;
            }

            $paused = true;
            app(SetProjectConnectionAutomationState::class)->handle($user, $project, $connection, paused: true);
        });

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());

        $this->assertTrue($paused);
        $this->assertSame('blocked', $status);
        $this->assertSame('automation_paused', $delivery->fresh()->last_error_code);
        $this->assertSame('pending', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->automation_paused_at);
        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(0, ProjectConnectionEventReceipt::query()->count());
    }

    private function outboxEvent(): DeploymentSucceededOutboxEvent
    {
        $buildId = DB::table('builds')->insertGetId([
            'environment_id' => $this->deployerEnvironmentId,
            'status' => 'succeeded',
            'revision' => str_repeat('c', 40),
            'release_name' => null,
            'built_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DeploymentSucceededOutboxEvent::query()->create([
            'event_type' => DeploymentSucceededOutboxEvent::EVENT_TYPE,
            'event_version' => 1,
            'source_build_id' => $buildId,
            'source_project_id' => 91,
            'source_environment_id' => $this->deployerEnvironmentId,
            'payload' => [
                'deployment_id' => (string) Str::uuid(),
                'version' => str_repeat('c', 40),
                'revision' => str_repeat('c', 40),
                'deployed_at' => now()->subMinutes(2)->utc()->toIso8601String(),
            ],
            'status' => 'pending',
            'available_at' => now(),
        ]);
    }

    private function makeDelivery(): ProjectConnectionDelivery
    {
        return ProjectConnectionDelivery::query()->create([
            'project_connection_id' => $this->connectionId,
            'source_event_id' => (string) Str::ulid(),
            'event_type' => DeploymentSucceededOutboxEvent::EVENT_TYPE,
            'event_version' => 1,
            'payload' => [
                'deployment_id' => (string) Str::uuid(),
                'version' => str_repeat('d', 40),
                'revision' => str_repeat('d', 40),
                'deployed_at' => now()->subMinutes(2)->utc()->toIso8601String(),
                'source_project_id' => '91',
                'source_environment_id' => (string) $this->deployerEnvironmentId,
                'source_build_id' => '15',
                'canonical_project_id' => $this->projectId,
                'canonical_environment_id' => $this->sourceEnvironmentId,
                'target_environment_id' => (string) $this->monitorEnvironmentId,
            ],
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }

    private function seedConnectedResources(): void
    {
        $this->workspaceId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        $this->sourceResourceId = (string) Str::ulid();
        $this->targetResourceId = (string) Str::ulid();
        $this->analyticsResourceId = (string) Str::ulid();
        $this->sourceEnvironmentId = (string) Str::ulid();
        $this->targetEnvironmentId = (string) Str::ulid();
        $this->connectionId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        $this->ownerUserId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $this->ownerUserId,
            'name' => 'Workspace owner',
            'email' => 'owner@example.test',
            'email_normalized' => 'owner@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deployerEnvironmentId = DB::table('environments')->insertGetId([
            'project_id' => 91,
            'name' => 'Production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $monitorWorkspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
            'name' => 'Monitor workspace',
            'slug' => 'monitor-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $monitorApplicationId = DB::connection('monitor')->table('applications')->insertGetId([
            'workspace_id' => $monitorWorkspaceId,
            'name' => 'Monitor application',
            'slug' => 'monitor-application',
            'status' => 'active',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->monitorEnvironmentId = DB::connection('monitor')->table('environments')->insertGetId([
            'application_id' => $monitorApplicationId,
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->analyticsSiteId = DB::connection('analytics')->table('sites')->insertGetId([
            'workspace_id' => 77,
            'name' => 'Analytics site',
            'slug' => 'analytics-site',
            'public_id' => Str::lower(Str::random(24)),
            'domains' => json_encode(['example.test'], JSON_THROW_ON_ERROR),
            'timezone' => 'UTC',
            'verification_token' => Str::random(48),
            'verified_at' => now(),
            'last_event_at' => null,
            'last_processed_at' => null,
            'collection_enabled' => true,
            'collection_paused_at' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'status' => 'active',
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->ownerUserId,
            'role' => 'owner',
            'status' => 'active',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            DB::connection('core')->table('workspace_product_access')->insert([
                'id' => (string) Str::ulid(),
                'membership_id' => $membershipId,
                'product' => $product,
                'status' => 'active',
                'expires_at' => null,
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('core')->table('project_products')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $this->projectId,
                'product' => $product,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('core')->table('projects')->insert([
            'id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'name' => 'Shared Project',
            'status' => 'active',
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'user_id' => $this->ownerUserId,
            'role' => 'owner',
            'status' => 'active',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_environments')->insert([
            ['id' => $this->sourceEnvironmentId, 'project_id' => $this->projectId, 'name' => 'Production', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->targetEnvironmentId, 'project_id' => $this->projectId, 'name' => 'Production Monitor', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('core')->table('project_resources')->insert([
            [
                'id' => $this->sourceResourceId,
                'project_id' => $this->projectId,
                'environment_id' => $this->sourceEnvironmentId,
                'product' => 'deployer',
                'resource_type' => 'environment',
                'resource_id' => (string) $this->deployerEnvironmentId,
                'name' => 'Deployer production',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->targetResourceId,
                'project_id' => $this->projectId,
                'environment_id' => $this->targetEnvironmentId,
                'product' => 'monitor',
                'resource_type' => 'environment',
                'resource_id' => (string) $this->monitorEnvironmentId,
                'name' => 'Monitor production',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->analyticsResourceId,
                'project_id' => $this->projectId,
                'environment_id' => null,
                'product' => 'analytics',
                'resource_type' => 'site',
                'resource_id' => (string) $this->analyticsSiteId,
                'name' => 'Analytics site',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $this->projectId,
                'environment_id' => null,
                'product' => 'deployer',
                'resource_type' => 'project',
                'resource_id' => '91',
                'name' => 'Deployer project',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::connection('core')->table('project_connections')->insert([
            'id' => $this->connectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $this->sourceResourceId,
            'target_resource_id' => $this->targetResourceId,
            'source_environment_id' => $this->sourceEnvironmentId,
            'target_environment_id' => $this->targetEnvironmentId,
            'capabilities' => json_encode(['deployment_context'], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'created_by_user_id' => null,
            'last_succeeded_at' => null,
            'last_error_code' => null,
            'last_error_at' => null,
            'disconnected_at' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bindEntitledPlans(): void
    {
        app()->instance(ProductPlanResolver::class, new class implements ProductPlanResolver
        {
            public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
            {
                return new ProductPlanResolution(
                    product: $product,
                    workspaceId: $workspaceId,
                    available: true,
                    planKey: $product->value,
                    subscriptionStatus: 'active',
                    entitlements: $product === ProductKey::Deployer ? ['monitoring', 'releases'] : ['*'],
                    limits: $product === ProductKey::Monitor ? ['deployment_context_minutes' => 60] : [],
                );
            }
        });
    }

    private function restrictedPlans(string $restriction): ProductPlanResolver
    {
        return new class($restriction) implements ProductPlanResolver
        {
            public function __construct(private readonly string $restriction) {}

            public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
            {
                $entitlements = $product === ProductKey::Deployer && $this->restriction === 'feature'
                    ? ['releases']
                    : ['*'];
                $limits = $product === ProductKey::Monitor
                    ? ['deployment_context_minutes' => $this->restriction === 'limit' ? 0 : 60]
                    : [];

                return new ProductPlanResolution(
                    product: $product,
                    workspaceId: $workspaceId,
                    available: true,
                    planKey: 'test',
                    subscriptionStatus: 'active',
                    entitlements: $entitlements,
                    limits: $limits,
                );
            }
        };
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('email_normalized')->unique();
            $table->string('password');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('workspace_id', 26);
            $table->string('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('membership_id', 26);
            $table->string('product');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('workspace_id', 26);
            $table->string('name');
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('product');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('name');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('environment_id', 26)->nullable();
            $table->string('product');
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('name')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('source_resource_id', 26);
            $table->string('target_resource_id', 26);
            $table->string('source_environment_id', 26)->nullable();
            $table->string('target_environment_id', 26)->nullable();
            $table->json('capabilities');
            $table->string('status');
            $table->string('created_by_user_id', 26)->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('automation_paused_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connection_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_connection_id', 26);
            $table->string('actor_user_id', 26)->nullable();
            $table->string('event_type', 40);
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connection_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_connection_id', 26);
            $table->string('source_event_id', 26);
            $table->string('event_type');
            $table->unsignedSmallInteger('event_version');
            $table->json('payload');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
            $table->unique(['source_event_id', 'project_connection_id']);
        });
    }

    private function createDeployerTables(): void
    {
        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('builds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id')->nullable();
            $table->string('status');
            $table->string('revision', 64)->nullable();
            $table->string('release_name')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('deployment_succeeded_outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type');
            $table->unsignedSmallInteger('event_version');
            $table->unsignedBigInteger('source_build_id');
            $table->unsignedBigInteger('source_project_id');
            $table->unsignedBigInteger('source_environment_id');
            $table->json('payload');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    private function createMonitorTables(): void
    {
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->unsignedBigInteger('event_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id');
            $table->string('service', 100)->nullable();
            $table->string('service_namespace', 100)->nullable();
            $table->string('version', 128);
            $table->char('service_hash', 64);
            $table->char('version_hash', 64);
            $table->timestamp('first_seen_at', 6)->nullable();
            $table->timestamp('last_seen_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['application_id', 'service_hash', 'version_hash']);
        });
        Schema::connection('monitor')->create('deployments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id');
            $table->foreignId('release_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('ingest_token_id')->nullable();
            $table->uuid('deployment_key');
            $table->char('payload_hash', 64);
            $table->string('source', 16);
            $table->string('commit_sha', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('deployed_at', 6);
            $table->timestamps(6);
            $table->unique(['environment_id', 'deployment_key']);
        });
        Schema::connection('monitor')->create('project_connection_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 26);
            $table->string('project_connection_id', 26);
            $table->string('handler', 100);
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('deployment_id');
            $table->timestamp('processed_at', 6);
            $table->timestamps(6);
            $table->unique(['delivery_id', 'handler']);
        });
        Schema::connection('monitor')->create('project_connection_incident_outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type', 64);
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->string('source_incident_id', 64);
            $table->string('source_environment_id', 64);
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at', 6)->nullable();
            $table->timestamp('dispatched_at', 6)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_error_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['event_type', 'source_incident_id']);
        });
    }

    private function createAnalyticsTables(): void
    {
        Schema::connection('analytics')->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('slug');
            $table->string('public_id', 32);
            $table->json('domains');
            $table->string('timezone', 64)->default('UTC');
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->boolean('collection_enabled')->default(true);
            $table->timestamp('collection_paused_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('analytics')->create('site_release_annotations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('delivery_id', 26);
            $table->string('handler', 100);
            $table->string('project_connection_id', 26);
            $table->uuid('deployment_id');
            $table->string('source_build_id', 64);
            $table->string('version', 128);
            $table->string('revision', 64)->nullable();
            $table->timestamp('deployed_at', 6);
            $table->char('payload_hash', 64);
            $table->timestamps(6);
            $table->unique(['delivery_id', 'handler']);
            $table->unique(['site_id', 'deployment_id']);
        });
        Schema::connection('analytics')->create('site_incident_annotations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('delivery_id', 26);
            $table->string('handler', 100);
            $table->string('project_connection_id', 26);
            $table->string('source_incident_id', 64);
            $table->string('status', 24);
            $table->timestamp('occurred_at', 6);
            $table->char('payload_hash', 64);
            $table->timestamps(6);
            $table->unique(['delivery_id', 'handler']);
        });
    }
}

final class ProjectConnectionDeliveryTestPlanResolver implements ProductPlanResolver
{
    public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
    {
        return new ProductPlanResolution(
            product: $product,
            workspaceId: $workspaceId,
            available: true,
            planKey: $product->value,
            subscriptionStatus: 'active',
            entitlements: $product === ProductKey::Deployer ? ['monitoring'] : [],
            limits: $product === ProductKey::Monitor ? ['deployment_context_minutes' => 60] : [],
        );
    }
}
