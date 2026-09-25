<?php

namespace Tests\Feature\Core;

use App\Core\Data\Notifications\WorkspaceNotification;
use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Modules\Deployer\Actions\Notification\UpdateNotificationStateAction;
use App\Modules\Deployer\Actions\Provider\InstallGitHubAppAction;
use App\Modules\Deployer\Enums\NotificationBulkOperation;
use App\Modules\Deployer\Http\Controllers\OrganizationDataController;
use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ActivityQuery;
use App\Modules\Deployer\Services\ControlPlaneAccess;
use App\Modules\Deployer\Services\Core\DeployerHistoryAccess;
use App\Modules\Deployer\Services\Core\DeployerNativeNotificationProvider;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceCustomerStatusManagementProvider;
use App\Modules\Deployer\Services\ExportOrganizationData;
use App\Modules\Deployer\Services\GitHubApp;
use App\Modules\Deployer\Services\NotificationInboxQuery;
use App\Modules\Deployer\Services\ProviderInventoryQuery;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Authored for the plan-completion run; exercises live membership decisions against the real Core schema. */
final class DeployerMappedProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlatformUser $platformUser;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('platform:migrate', ['module' => 'core']);
        Queue::fake();
        config(['platform.products.deployer.auth_authority' => 'core']);

        $this->actor = User::factory()->create();
        $this->platformUser = PlatformUser::query()->create([
            'name' => 'Project member', 'email' => 'project-member@example.test',
            'email_normalized' => 'project-member@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->platformUser->getKey(), 'name' => 'Team', 'slug' => 'team', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->platformUser->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active',
        ]);
        $this->map('user', $this->actor->getKey(), 'user', $this->platformUser->getKey());
        $this->map('organization', $this->actor->current_organization_id, 'workspace', $this->workspace->getKey());
    }

    public function test_revocation_denies_native_project_environment_and_attached_resources_without_removing_local_roles(): void
    {
        [$project, $environment, $membership] = $this->mappedProject('checkout');
        $provider = $this->actor->providers()->create(['name' => 'Git', 'provider' => 'github', 'token' => 'secret', 'description' => 'Git']);
        $server = $this->actor->servers()->create(['name' => 'Production', 'provider_id' => $provider->id]);
        $website = $this->actor->websites()->create([
            'server_id' => $server->id, 'name' => 'Checkout', 'description' => 'Checkout', 'url' => 'checkout.example.test',
        ]);
        $repository = $this->actor->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Checkout',
            'description' => 'Checkout', 'url' => 'github.com/example/checkout.git',
        ]);
        $environment->update(['server_id' => $server->id, 'website_id' => $website->id]);
        $build = $repository->builds()->create(['environment_id' => $environment->id, 'status' => Build::STATUS_SUCCEEDED]);
        $access = app(DeployerProjectAccess::class);

        foreach ([$project, $environment, $server, $website, $repository, $build] as $resource) {
            $this->assertTrue($this->actor->can('view', $resource));
        }
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        foreach ([$project, $environment, $server, $website, $repository, $build] as $resource) {
            $this->assertFalse($this->actor->can('view', $resource));
        }
        $this->assertFalse($this->actor->can('createEnvironment', $project));
        $this->assertFalse($this->actor->can('manageConfiguration', $project));
        $this->assertFalse($this->actor->can('deploy', $repository));
        $this->assertFalse($this->actor->can('rollback', $build));
        $this->assertSame('owner', $project->organization->roleFor($this->actor));
        $this->assertSame(0, $this->actor->workspaceProjects()->count());
        $this->assertSame(0, $this->actor->workspaceServers()->count());
        $this->assertSame(0, $this->actor->workspaceWebsites()->count());
        $this->assertSame(0, $this->actor->workspaceRepositories()->count());
        $this->assertSame(0, $access->builds(Build::query(), $this->actor)->count());
    }

    public function test_collections_exclude_revoked_projects_before_limit_and_keep_unmapped_projects(): void
    {
        [$revoked, , $membership] = $this->mappedProject('a-revoked');
        [$allowed] = $this->mappedProject('b-allowed');
        $unmapped = $this->localProject('c-unmapped');
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertSame(2, $this->actor->workspaceProjects()->count());
        $this->assertSame([$allowed->id], $this->actor->workspaceProjects()->orderBy('name')->limit(1)->pluck('projects.id')->all());
        $this->assertTrue($this->actor->can('view', $unmapped));
        $this->assertFalse($this->actor->can('view', $revoked));

        $token = $this->actor->createToken('Legacy wide token', ['read'])->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/projects');
        $response->assertOk();
        $this->assertEqualsCanonicalizing([$allowed->id, $unmapped->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_project_scoped_tokens_do_not_override_revoked_membership(): void
    {
        [$project, , $membership] = $this->mappedProject('target');
        $token = $this->actor->createToken('Project token', ['read', 'workspace:'.$project->organization_id, 'project:'.$project->id]);
        $this->actor->withAccessToken($token->accessToken);
        $request = Request::create('/api/v1/projects/'.$project->id);
        $request->setUserResolver(fn (): User => $this->actor);
        app(ControlPlaneAccess::class)->enforceProject($request, $project->id);

        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        try {
            app(ControlPlaneAccess::class)->enforceProject($request, $project->id);
            $this->fail('Token claims must not restore revoked canonical project membership.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_legacy_authority_preserves_local_project_permissions_after_core_revocation(): void
    {
        [$project, $environment, $membership] = $this->mappedProject('legacy');
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        config(['platform.products.deployer.auth_authority' => 'legacy']);

        $this->assertTrue($this->actor->can('view', $project));
        $this->assertTrue($this->actor->can('update', $environment));
        $this->assertSame([$project->id], $this->actor->workspaceProjects()->pluck('projects.id')->all());
    }

    public function test_core_product_revocation_denies_even_unmapped_source_projects_and_tokens(): void
    {
        $project = $this->localProject('unmapped');
        WorkspaceProductAccess::query()->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertFalse($this->actor->can('view', $project));
        $this->assertSame(0, $this->actor->workspaceProjects()->count());
        $token = $this->actor->createToken('Previously issued token', ['read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/projects')->assertForbidden();
    }

    public function test_notification_and_activity_payloads_counts_and_bulk_mutations_follow_live_project_membership(): void
    {
        $hidden = $this->resources('hidden-history');
        $visible = $this->resources('visible-history');
        $hiddenNotification = $this->notification('website', $hidden['website']->id);
        $visibleNotification = $this->notification('website', $visible['website']->id);
        $accountNotification = $this->notification('account', $this->actor->id);
        $hiddenEvent = $hidden['website']->events()->create(['user_id' => $this->actor->id, 'category' => 'website', 'event' => 'Private site detail']);
        $visibleEvent = $visible['website']->events()->create(['user_id' => $this->actor->id, 'category' => 'website', 'event' => 'Visible site detail']);
        $hidden['membership']->update(['status' => 'revoked', 'revoked_at' => now()]);
        $filters = array_fill_keys(['search', 'category', 'status', 'state', 'date_from', 'date_to'], null);
        $inbox = app(NotificationInboxQuery::class);
        $activityFilters = [...$filters, 'search' => 'site detail'];

        $this->assertEqualsCanonicalizing([$visibleNotification->id, $accountNotification->id], $inbox->for($this->actor, $filters)->pluck('id')->all());
        $this->assertSame(2, $inbox->metrics($this->actor, $filters)['total']);
        $this->assertFalse($this->actor->can('read', $hiddenNotification));
        $this->assertTrue($this->actor->can('read', $visibleNotification));
        $this->assertSame([$visibleEvent->id], app(ActivityQuery::class)->for($this->actor, $activityFilters)->orderBy('id')->limit(1)->pluck('id')->all());
        $this->assertSame(1, app(ActivityQuery::class)->metrics($this->actor, $activityFilters)['total']);

        app(UpdateNotificationStateAction::class)->markAllRead($this->actor);
        $this->assertNull($hiddenNotification->fresh()->read_at);
        $this->assertNotNull($visibleNotification->fresh()->read_at);
        $this->assertSame(0, app(UpdateNotificationStateAction::class)->bulk($this->actor, NotificationBulkOperation::Delete, [$hiddenNotification->id]));
        $this->assertSame(2, app(UpdateNotificationStateAction::class)->clearRead($this->actor));
        $this->assertNotNull($hiddenNotification->fresh());
        $this->assertNotNull($hiddenEvent->fresh());

        config(['platform.products.deployer.auth_authority' => 'legacy']);
        $this->assertSame([$hiddenNotification->id], $inbox->for($this->actor, $filters)->pluck('id')->all());
        $this->assertTrue($this->actor->can('read', $hiddenNotification));
        $this->assertSame(2, app(ActivityQuery::class)->metrics($this->actor, $activityFilters)['total']);
    }

    public function test_global_recipient_history_remains_visible_across_authorized_workspace_switches(): void
    {
        $resource = $this->resources('other-workspace-history');
        $notification = $this->notification('website', $resource['website']->id);
        $originalOrganization = $this->actor->current_organization_id;
        $other = $this->actor->currentOrganization->replicate();
        $other->name = 'Other team';
        $other->slug = 'other-history-team';
        $other->save();
        $coreOther = Workspace::query()->create([
            'owner_user_id' => $this->platformUser->id, 'name' => 'Other team', 'slug' => 'other-history-team', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $coreOther->id, 'user_id' => $this->platformUser->id, 'role' => 'owner', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create(['membership_id' => $membership->id, 'product' => 'deployer', 'role' => 'owner', 'status' => 'active']);
        $this->map('organization', $other->id, 'workspace', $coreOther->id);
        $this->actor->update(['current_organization_id' => $other->id]);
        $this->actor->setRelation('currentOrganization', $other);

        $history = app(DeployerHistoryAccess::class);
        $this->assertSame([$notification->id], $history->notifications($this->actor->notifications(), $this->actor)->pluck('id')->all());
        $this->assertSame($other->id, $this->actor->fresh()->current_organization_id);
        $this->assertNotSame($originalOrganization, $this->actor->current_organization_id);
        $resource['membership']->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertSame(0, $history->notifications($this->actor->notifications(), $this->actor)->count());
    }

    public function test_explicit_personal_resource_mappings_cannot_bypass_history_access(): void
    {
        [, , $membership] = $this->mappedProject('personal-history');
        $provider = $this->actor->providers()->create(['name' => 'Personal', 'provider' => 'github', 'token' => 'secret', 'description' => 'Personal']);
        $server = $this->actor->servers()->create(['name' => 'Personal server', 'provider_id' => $provider->id]);
        $server->forceFill(['organization_id' => null])->save();
        ProjectResource::query()->create([
            'project_id' => $membership->project_id, 'product' => 'deployer', 'resource_type' => 'server',
            'resource_id' => (string) $server->id, 'status' => 'active',
        ]);
        $notification = $this->notification('server', $server->id);
        $history = app(DeployerHistoryAccess::class);
        $this->assertSame([$notification->id], $history->notifications($this->actor->notifications(), $this->actor)->pluck('id')->all());
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertSame(0, $history->notifications($this->actor->notifications(), $this->actor)->count());
        $this->assertFalse($this->actor->can('view', $server));
    }

    public function test_provider_association_counts_and_credential_mutations_respect_attached_project_access(): void
    {
        $resource = $this->resources('provider-access');
        $inventory = app(ProviderInventoryQuery::class);
        $filters = array_fill_keys(['search', 'type', 'usage', 'connection'], null);
        $this->assertTrue($this->actor->can('update', $resource['provider']));
        $resource['membership']->update(['status' => 'revoked', 'revoked_at' => now()]);

        $provider = $inventory->for($this->actor, $filters)->withCount($inventory->associations($this->actor))->sole();
        $this->assertSame(0, $provider->servers_count);
        $this->assertSame(0, $provider->repositories_count);
        $this->assertSame(0, $inventory->metrics($this->actor, $filters)['in_use']);
        $this->assertSame(1, $inventory->metrics($this->actor, $filters)['unused']);
        $this->assertTrue($this->actor->can('view', $provider));
        $this->assertFalse($this->actor->can('update', $provider));
        $this->assertFalse($this->actor->can('delete', $provider));
        // Operational ownership and deletion safety still see every attached row.
        $this->assertSame(1, $provider->servers()->count());
        $this->assertSame(1, $provider->repositories()->count());
        $this->assertSame(1, $this->actor->currentOrganization->servers()->count());
        config(['platform.products.deployer.auth_authority' => 'legacy']);
        $this->assertTrue($this->actor->can('update', $provider));
        $this->assertSame(1, $inventory->metrics($this->actor, $filters)['in_use']);
    }

    public function test_github_reinstallation_cannot_overwrite_or_duplicate_an_inaccessible_shared_connection(): void
    {
        $resource = $this->resources('github-reinstall');
        $resource['provider']->forceFill(['credential_type' => 'app', 'external_id' => 'installation-1'])->save();
        $resource['membership']->update(['status' => 'revoked', 'revoked_at' => now()]);
        $github = Mockery::mock(GitHubApp::class);
        $github->shouldNotReceive('repositories');
        $session = Mockery::mock(Session::class);
        $session->shouldReceive('pull')->once()->with('github_app_installation_state')->andReturn(hash('sha256', 'callback-state'));

        try {
            (new InstallGitHubAppAction($github, $session))->handle($this->actor, 'installation-1', 'callback-state');
            $this->fail('An installation callback cannot modify a connection used by a revoked project.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, $this->actor->currentOrganization->providers()->count());
        $this->assertSame('github-reinstall', $resource['provider']->fresh()->name);
    }

    public function test_status_and_balancer_management_require_every_linked_project_resource(): void
    {
        $allowed = $this->resources('allowed-shared');
        $revoked = $this->resources('revoked-shared');
        $organization = $this->actor->currentOrganization;
        $page = $organization->statusPages()->create(['created_by' => $this->actor->id, 'name' => 'Shared status', 'slug' => 'shared-status', 'is_published' => true]);
        $page->websites()->attach([$allowed['website']->id, $revoked['website']->id]);
        $incident = $page->incidents()->create(['title' => 'Private incident', 'message' => 'Affected services', 'status' => 'investigating', 'starts_at' => now()]);
        $balancer = $organization->loadBalancers()->create([
            'environment_id' => $allowed['environment']->id, 'server_id' => $allowed['server']->id,
            'created_by' => $this->actor->id, 'hostname' => 'shared-lb.example.test',
        ]);
        $balancer->nodes()->create(['server_id' => $revoked['server']->id]);
        $this->assertTrue($this->actor->can('update', $page));
        $this->assertTrue($this->actor->can('manage', $balancer));
        $revoked['membership']->update(['status' => 'revoked', 'revoked_at' => now()]);
        $access = app(DeployerProjectAccess::class);

        $this->assertFalse($this->actor->can('update', $page));
        $this->assertFalse($this->actor->can('delete', $page));
        $this->assertFalse($this->actor->can('update', $incident));
        $this->assertFalse($this->actor->can('manage', $balancer));
        $this->assertSame(0, $access->statusPages($organization->statusPages(), $this->actor)->count());
        $this->assertSame(0, $access->loadBalancers($organization->loadBalancers(), $this->actor)->count());
        // Visibility controls private management only; published status content remains published.
        $this->assertTrue($page->fresh()->is_published);
        $this->assertSame(2, $page->websites()->count());
        config(['platform.products.deployer.enabled' => true]);
        $core = app(DeployerWorkspaceCustomerStatusManagementProvider::class);
        $this->assertSame([], $core->forWorkspace($this->platformUser, $this->workspace)->pages);
        $this->assertFalse($core->deletePage($this->platformUser, $this->workspace, (string) $page->id));
        $this->assertFalse($core->createIncident($this->platformUser, $this->workspace, ['status_page_id' => $page->id]));
        config(['platform.products.deployer.auth_authority' => 'legacy']);
        $this->assertTrue($this->actor->can('update', $page));
        $this->assertTrue($this->actor->can('manage', $balancer));
    }

    public function test_shared_backup_credentials_and_workspace_alert_routes_cannot_affect_revoked_projects(): void
    {
        $resource = $this->resources('shared-destinations');
        $destination = $this->actor->currentOrganization->backupDestinations()->create([
            'created_by' => $this->actor->id, 'name' => 'Shared archive', 'endpoint' => 'https://archive.example.test',
            'bucket' => 'backups', 'access_key' => 'key', 'secret_key' => 'secret', 'repository_password' => 'password',
        ]);
        $destination->backups()->create(['website_id' => $resource['website']->id, 'status' => 'completed']);
        $this->assertTrue($this->actor->can('update', $destination));
        $this->assertTrue($this->actor->can('create', AlertDestination::class));
        $resource['membership']->update(['status' => 'revoked', 'revoked_at' => now()]);

        foreach (['update', 'delete', 'test'] as $ability) {
            $this->assertFalse($this->actor->can($ability, $destination));
        }
        $this->assertFalse($this->actor->can('create', AlertDestination::class));
        $this->assertSame(1, $destination->backups()->count());
    }

    public function test_metric_and_task_history_is_retained_only_when_its_source_access_is_provable(): void
    {
        $resource = $this->resources('metric-history');
        $organization = $this->actor->currentOrganization;
        $rule = $organization->metricAlertRules()->create([
            'created_by' => $this->actor->id, 'server_id' => $resource['server']->id,
            'name' => 'CPU', 'metric' => 'cpu_percent', 'threshold' => 80,
        ]);
        $globalRule = $organization->metricAlertRules()->create([
            'created_by' => $this->actor->id, 'name' => 'Every server CPU', 'metric' => 'cpu_percent', 'threshold' => 80,
        ]);
        $task = $resource['environment']->scheduledTasks()->create([
            'created_by' => $this->actor->id, 'name' => 'Reports', 'command' => 'php artisan reports', 'cron_expression' => '* * * * *',
        ]);
        $this->notification('metric', $rule->id);
        $this->notification('metric', $globalRule->id);
        $this->notification('scheduled_task', $task->id);
        $incident = $organization->operationalIncidents()->create([
            'category' => 'metric', 'resource_id' => $globalRule->id, 'title' => 'Private server CPU',
            'summary' => 'CPU threshold breached', 'detected_at' => now(), 'last_seen_at' => now(),
        ]);
        $history = app(DeployerHistoryAccess::class);
        $access = app(DeployerProjectAccess::class);
        $this->assertSame(3, $history->notifications($this->actor->notifications(), $this->actor)->count());
        config(['platform.products.deployer.url' => 'https://deployer.example.test']);
        $nativeFeed = app(DeployerNativeNotificationProvider::class)->forWorkspace(
            $this->platformUser,
            $this->workspace,
            collect(),
            ['deployer'],
            100,
        );
        $this->assertEqualsCanonicalizing(['metric', 'metric', 'scheduled_task'], $nativeFeed->notifications->pluck('sourceCategory')->all());
        $this->assertSame(
            ['https://deployer.example.test/automation', 'https://deployer.example.test/observability', 'https://deployer.example.test/observability'],
            $nativeFeed->notifications->pluck('resultUrl')->sort()->values()->all(),
        );
        $this->assertTrue($this->actor->can('delete', $globalRule));
        $this->assertSame([$incident->id], $access->incidents($organization->operationalIncidents(), $this->actor)->pluck('id')->all());
        $resource['membership']->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertSame(0, $history->notifications($this->actor->notifications(), $this->actor)->count());
        $this->assertSame(0, $access->incidents($organization->operationalIncidents(), $this->actor)->count());
        $this->assertFalse($this->actor->can('delete', $globalRule));
        $this->assertSame(3, $this->actor->notifications()->count());
        config(['platform.products.deployer.auth_authority' => 'legacy']);
        $this->assertSame(3, $history->notifications($this->actor->notifications(), $this->actor)->count());
        $this->assertTrue($this->actor->can('delete', $globalRule));
    }

    public function test_deployment_history_accepts_environment_owned_builds_and_rejects_conflicting_owners(): void
    {
        $resource = $this->resources('environment-build-history');
        $environmentOnlyBuild = $resource['build'];
        $environmentOnlyBuild->update(['repository_id' => null]);
        $visible = $this->notification('deployment', $environmentOnlyBuild->getKey());

        $otherOrganization = $this->actor->currentOrganization->replicate();
        $otherOrganization->name = 'Conflicting build owner';
        $otherOrganization->slug = 'conflicting-build-owner';
        $otherOrganization->save();
        $resource['repository']->update(['organization_id' => $otherOrganization->getKey()]);
        $conflictingBuild = $resource['repository']->builds()->create([
            'environment_id' => $resource['environment']->getKey(),
            'status' => Build::STATUS_SUCCEEDED,
        ]);
        $hidden = $this->notification('deployment', $conflictingBuild->getKey());

        $feed = app(DeployerNativeNotificationProvider::class)->forWorkspace(
            $this->platformUser,
            $this->workspace,
            collect(),
            ['deployer'],
            100,
        );
        $sourceIds = collect($feed->notifications)
            ->map(fn (WorkspaceNotification $item): string => Crypt::decryptString($item->sourceReference))
            ->all();

        $this->assertContains((string) $visible->getKey(), $sourceIds);
        $this->assertNotContains((string) $hidden->getKey(), $sourceIds);
    }

    public function test_newer_notifications_from_another_authorized_workspace_do_not_crowd_out_current_workspace_items(): void
    {
        $target = $this->resources('target-workspace-notification');
        $targetNotification = $this->notification('website', $target['website']->id);
        $targetNotification->forceFill(['created_at' => now()->subHour()])->save();

        $otherOrganization = $this->actor->currentOrganization->replicate();
        $otherOrganization->name = 'Other team';
        $otherOrganization->slug = 'other-team-notifications';
        $otherOrganization->save();
        $otherWorkspace = Workspace::query()->create([
            'owner_user_id' => $this->platformUser->getKey(),
            'name' => 'Other team',
            'slug' => 'other-team-notifications',
            'status' => 'active',
        ]);
        $otherMembership = WorkspaceMembership::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'user_id' => $this->platformUser->getKey(),
            'role' => 'owner',
            'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $otherMembership->getKey(),
            'product' => 'deployer',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->map('organization', $otherOrganization->getKey(), 'workspace', (string) $otherWorkspace->getKey());
        $this->actor->forceFill(['current_organization_id' => $otherOrganization->getKey()])->save();
        $this->actor->setRelation('currentOrganization', $otherOrganization);
        $targetWorkspace = $this->workspace;
        $this->workspace = $otherWorkspace;
        $otherResource = $this->resources('other-workspace-notification');
        $this->workspace = $targetWorkspace;

        for ($index = 0; $index < 105; $index++) {
            $this->notification('website', $otherResource['website']->getKey());
        }

        $feed = app(DeployerNativeNotificationProvider::class)->forWorkspace(
            $this->platformUser,
            $this->workspace,
            collect(),
            ['deployer'],
            100,
        );

        $this->assertSame([(string) $targetNotification->getKey()], collect($feed->notifications)
            ->map(fn (WorkspaceNotification $item): string => Crypt::decryptString($item->sourceReference))
            ->values()->all());
    }

    public function test_full_workspace_export_preserves_archived_history_but_requires_current_membership(): void
    {
        $resource = $this->resources('archived-history');
        $resource['website']->delete();
        $resource['repository']->delete();
        CoreProject::query()->whereKey($resource['membership']->project_id)->update(['status' => 'archived', 'archived_at' => now()]);
        ProjectProduct::query()->where('project_id', $resource['membership']->project_id)->update(['status' => 'inactive']);
        ProjectResource::query()->where('project_id', $resource['membership']->project_id)->update(['status' => 'archived']);
        $request = Request::create('/workspaces/data/export');
        $request->setUserResolver(fn (): User => $this->actor);
        $controller = app(OrganizationDataController::class);

        $this->assertFalse($this->actor->can('view', $resource['project']));
        $this->assertSame(1, app(DeployerProjectAccess::class)->projects($this->actor->currentOrganization->projects(), $this->actor, ProjectResourceAccessPurpose::HistoricalExport)->count());
        $this->assertSame(200, $controller->export($request, app(ExportOrganizationData::class))->getStatusCode());
        $output = fopen('php://temp', 'w+');
        app(ExportOrganizationData::class)->write($this->actor->currentOrganization, $output);
        rewind($output);
        $records = collect(explode("\n", trim(stream_get_contents($output))))->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        fclose($output);
        $this->assertSame($resource['website']->id, $records->firstWhere('type', 'website')['data']['id']);
        $this->assertNotNull($records->firstWhere('type', 'website')['data']['deleted_at']);
        $this->assertSame($resource['build']->id, $records->firstWhere('type', 'build')['data']['id']);

        $resource['membership']->update(['status' => 'revoked', 'revoked_at' => now()]);
        try {
            $controller->export($request, app(ExportOrganizationData::class));
            $this->fail('Historical export must not restore revoked project membership.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_full_export_rejects_revoked_product_access_even_when_the_workspace_has_no_projects(): void
    {
        WorkspaceProductAccess::query()->update(['status' => 'revoked', 'revoked_at' => now()]);
        $request = Request::create('/workspaces/data/export');
        $request->setUserResolver(fn (): User => $this->actor);

        $this->expectException(HttpException::class);
        app(OrganizationDataController::class)->export($request, app(ExportOrganizationData::class));
    }

    /** @return array<string, mixed> */
    private function resources(string $name): array
    {
        [$project, $environment, $membership] = $this->mappedProject($name);
        $provider = $this->actor->providers()->create(['name' => $name, 'provider' => 'github', 'token' => 'secret', 'description' => $name]);
        $server = $this->actor->servers()->create(['name' => $name, 'provider_id' => $provider->id]);
        $website = $this->actor->websites()->create([
            'server_id' => $server->id, 'name' => $name, 'description' => $name, 'url' => $name.'.example.test',
        ]);
        $repository = $this->actor->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => $name,
            'description' => $name, 'url' => 'github.com/example/'.$name.'.git',
        ]);
        $environment->update(['server_id' => $server->id, 'website_id' => $website->id]);
        $build = $repository->builds()->create(['environment_id' => $environment->id, 'status' => Build::STATUS_SUCCEEDED]);

        return compact('project', 'environment', 'membership', 'provider', 'server', 'website', 'repository', 'build');
    }

    private function notification(string $category, int $resourceId): DatabaseNotification
    {
        return $this->actor->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'project-update',
            'data' => ['category' => $category, 'resource_id' => $resourceId, 'status' => 'info', 'title' => 'Resource update', 'message' => 'Retained resource detail'],
        ]);
    }

    /** @return array{Project, Environment, ProjectMembership} */
    private function mappedProject(string $name): array
    {
        $source = $this->localProject($name);
        $environment = $source->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production']);
        $project = CoreProject::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => $name, 'slug' => $name, 'status' => 'active',
        ]);
        $membership = ProjectMembership::query()->create([
            'project_id' => $project->getKey(), 'user_id' => $this->platformUser->getKey(), 'role' => 'member', 'status' => 'active',
        ]);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'deployer', 'status' => 'active']);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(), 'product' => 'deployer', 'resource_type' => 'project',
            'resource_id' => (string) $source->id, 'status' => 'active',
        ]);

        return [$source, $environment, $membership];
    }

    private function localProject(string $name): Project
    {
        return $this->actor->currentOrganization->projects()->create(['name' => $name, 'slug' => $name, 'created_by' => $this->actor->id]);
    }

    private function map(string $entity, string|int $source, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => $entity, 'source_id' => (string) $source,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }
}
