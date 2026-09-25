<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\WorkspaceActivityProvider;
use App\Core\Data\Notifications\WorkspaceNotificationSeverity;
use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Data\Projects\WorkspaceActivitySnapshot;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use App\Core\Services\WorkspaceNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

final class WorkspaceNotificationsTest extends TestCase
{
    private PlatformUser $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('core')->create('workspace_notification_reads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->char('notification_key', 64);
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'notification_key']);
        });
        Schema::connection('core')->create('workspace_notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->char('project_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('severity', 24);
            $table->char('scope_key', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'scope_key']);
        });

        $this->user = new PlatformUser;
        $this->user->forceFill(['id' => (string) Str::ulid(), 'name' => 'Alex Owner']);
        $this->user->exists = true;

        $this->workspace = new Workspace;
        $this->workspace->forceFill([
            'id' => (string) Str::ulid(),
            'name' => 'Northstar Studio',
            'slug' => 'northstar-studio',
        ]);
        $this->workspace->exists = true;

        $this->project = new Project;
        $this->project->forceFill([
            'id' => (string) Str::ulid(),
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Checkout app',
            'slug' => 'checkout-app',
        ]);
        $this->project->exists = true;
    }

    protected function tearDown(): void
    {
        Schema::connection('core')->dropIfExists('workspace_notification_preferences');
        Schema::connection('core')->dropIfExists('workspace_notification_reads');

        parent::tearDown();
    }

    public function test_notification_uses_the_accessible_core_project_canonical_environment_and_configured_product_host(): void
    {
        config(['platform.products.monitor.url' => 'https://monitor.example.test']);
        $run = $this->incidentRun((string) $this->project->getKey());
        $registry = app(WorkspaceActivityProviderRegistry::class);
        $registry->register('monitor', $this->provider(new WorkspaceActivitySnapshot(collect([$run]))));

        $notification = app(WorkspaceNotifications::class)
            ->forWorkspace($this->user, $this->workspace, collect([$this->project]), ['monitor'])
            ->notifications
            ->sole();

        $this->assertSame('Checkout app', $notification->projectName);
        $this->assertSame(route('core.projects.show', [$this->workspace, $this->project]), $notification->projectUrl);
        $this->assertSame((string) $this->project->getKey().'|environment:01J8AA00000000000000000001', $notification->threadKey);
        $this->assertSame('Production', $notification->environmentName);
        $this->assertSame('critical', $notification->severity->value);
        $this->assertSame('https://monitor.example.test/incidents/42?workspace_id=81', $notification->resultUrl);
        $this->assertStringNotContainsString('attacker.example.test', $notification->resultUrl);
        $this->assertSame('Open Monitor for authorized details.', $notification->detail);
    }

    public function test_read_state_is_per_user_and_project_preference_overrides_workspace_default(): void
    {
        $registry = app(WorkspaceActivityProviderRegistry::class);
        $registry->register('monitor', $this->provider(new WorkspaceActivitySnapshot(collect([
            $this->incidentRun((string) $this->project->getKey()),
        ]))));
        $service = app(WorkspaceNotifications::class);
        $feed = $service->forWorkspace($this->user, $this->workspace, collect([$this->project]), ['monitor']);
        $notification = $feed->notifications->sole();

        $otherUserId = (string) Str::ulid();
        DB::connection('core')->table('workspace_notification_reads')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $otherUserId,
            'notification_key' => $notification->key,
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertFalse($service->forWorkspace($this->user, $this->workspace, collect([$this->project]), ['monitor'])->notifications->sole()->read);

        DB::connection('core')->table('workspace_notification_reads')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->user->getKey(),
            'notification_key' => $notification->key,
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $readFeed = $service->forWorkspace($this->user, $this->workspace, collect([$this->project]), ['monitor']);
        $this->assertTrue($readFeed->notifications->sole()->read);
        $this->assertSame(0, $readFeed->unreadCount);

        $this->preference(projectId: null, enabled: false);
        $this->assertCount(0, $service->forWorkspace($this->user, $this->workspace, collect([$this->project]), ['monitor'])->notifications);

        $this->preference(projectId: (string) $this->project->getKey(), enabled: true);
        $projectOverride = $service->forWorkspace($this->user, $this->workspace, collect([$this->project]), ['monitor']);
        $this->assertCount(1, $projectOverride->notifications);
        $this->assertTrue($projectOverride->notifications->sole()->read);
    }

    public function test_product_result_link_is_omitted_when_its_trusted_origin_is_not_configured(): void
    {
        config([
            'platform.products.monitor.url' => null,
            'platform.products.monitor.host' => null,
        ]);
        $registry = app(WorkspaceActivityProviderRegistry::class);
        $registry->register('monitor', $this->provider(new WorkspaceActivitySnapshot(collect([
            $this->incidentRun((string) $this->project->getKey()),
        ]))));

        $notification = app(WorkspaceNotifications::class)
            ->forWorkspace($this->user, $this->workspace, collect([$this->project]), ['monitor'])
            ->notifications
            ->sole();

        $this->assertNull($notification->resultUrl);
    }

    public function test_a_product_database_outage_does_not_hide_updates_from_another_product(): void
    {
        $registry = app(WorkspaceActivityProviderRegistry::class);
        $registry->register('monitor', new class implements WorkspaceActivityProvider
        {
            public function recentForWorkspace(PlatformUser $user, Workspace $workspace, Collection $projects, int $limit): WorkspaceActivitySnapshot
            {
                throw new PDOException('database credential must not render');
            }
        });
        $registry->register('deployer', $this->provider(new WorkspaceActivitySnapshot(collect([
            $this->incidentRun((string) $this->project->getKey(), 'deployer'),
        ]))));

        $feed = app(WorkspaceNotifications::class)
            ->forWorkspace($this->user, $this->workspace, collect([$this->project]), ['monitor', 'deployer']);

        $this->assertCount(1, $feed->notifications);
        $this->assertSame('deployer', $feed->notifications->sole()->product);
        $this->assertSame(['Monitor'], $feed->unavailableProducts->all());
    }

    private function provider(WorkspaceActivitySnapshot $snapshot): WorkspaceActivityProvider
    {
        return new class($snapshot) implements WorkspaceActivityProvider
        {
            public function __construct(private readonly WorkspaceActivitySnapshot $snapshot) {}

            public function recentForWorkspace(PlatformUser $user, Workspace $workspace, Collection $projects, int $limit): WorkspaceActivitySnapshot
            {
                return $this->snapshot;
            }
        };
    }

    private function incidentRun(string $projectId, string $product = 'monitor'): ProjectWorkflowRun
    {
        $recordedAt = CarbonImmutable::parse('2026-09-25 12:00:00', 'UTC');

        return new ProjectWorkflowRun(
            key: $product.':incident:42:open:1727265600',
            title: 'Private incident title',
            recordedAt: $recordedAt,
            projectId: $projectId,
            projectName: 'Forged project name',
            projectUrl: 'https://attacker.example.test/forged-project',
            steps: [new ProjectWorkflowStep(
                product: $product,
                productLabel: ucfirst($product),
                title: 'Incident · Production',
                detail: 'Open Monitor for authorized details.',
                state: ProjectWorkflowStepState::Failed,
                recordedAt: $recordedAt,
                resultUrl: 'https://attacker.example.test/incidents/42?workspace_id=81',
                environmentId: '01J8AA00000000000000000001',
                environmentName: 'Production',
            )],
        );
    }

    private function preference(?string $projectId, bool $enabled): void
    {
        $severity = 'critical';
        DB::connection('core')->table('workspace_notification_preferences')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->user->getKey(),
            'project_id' => $projectId,
            'product' => 'monitor',
            'severity' => $severity,
            'scope_key' => WorkspaceNotifications::preferenceScopeKey(
                $projectId,
                'monitor',
                WorkspaceNotificationSeverity::from($severity),
            ),
            'enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
