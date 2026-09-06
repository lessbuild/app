<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Models\Website;
use App\Services\PublicPlatformStatus;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_public_landing_page(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertSee('Deploy with clarity. Recover with confidence.')
            ->assertSee('The release lifecycle, without the tool sprawl.')
            ->assertSee('Connect. Provision. Deploy.')
            ->assertSee(route('login'));
    }

    public function test_authenticated_root_visits_redirect_into_the_dashboard_verification_flow(): void
    {
        $verified = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($verified)->get('/')->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertSuccessful();

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get('/')->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_only_the_authenticated_users_activity(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $repository = $this->createResources($user, 'My Application');
        $this->createResources($otherUser, 'Someone Else Application');
        Build::create(['repository_id' => $repository->id, 'built_at' => now()]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertSuccessful()
            ->assertSee('My Application')
            ->assertSee('Recent websites')
            ->assertSee('Recent builds')
            ->assertSee('Recent activity')
            ->assertSee('No active failures')
            ->assertSee(route('activity.index'))
            ->assertSee(route('builds.show', $repository->builds()->sole()))
            ->assertDontSee('Someone Else Application');
    }

    public function test_empty_dashboard_offers_useful_next_actions(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response
            ->assertSuccessful()
            ->assertSee('Operational overview')
            ->assertSee('Deployment volume')
            ->assertSee('Health reliability')
            ->assertSee('Plan capacity')
            ->assertSee('No websites yet')
            ->assertSee('No builds yet')
            ->assertSee('No activity yet')
            ->assertSee('Get to your first healthy deployment')
            ->assertSee('0 of 5 complete')
            ->assertSee('Connect a provider')
            ->assertSee('Current step')
            ->assertSee(route('providers.create'))
            ->assertSee('Available after the previous step')
            ->assertDontSee('Active deployments')
            ->assertDontSee('Active server commands')
            ->assertDontSee('Webhook deliveries')
            ->assertDontSee('Infrastructure provisioning')
            ->assertDontSee('Community recipe feedback')
            ->assertSee('System operational')
            ->assertSee('View system health')
            ->assertSee(route('system-health.index'))
            ->assertSee(route('servers.create'))
            ->assertSee(route('websites.create'));

        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('dashboard'), '/').'" class="[^"]*bg-secondary[^"]*"[^>]*aria-current="page"[^>]*>\s*<svg[^>]*>.*?Dashboard/s',
            $response->getContent(),
        );
    }

    public function test_dashboard_setup_progresses_in_dependency_order_and_hides_after_a_successful_deployment(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $repository = $this->createResources($user, 'Onboarding Application');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('onboarding', [
                'provider' => true,
                'server' => true,
                'website' => true,
                'repository' => true,
                'deployment' => false,
            ])
            ->assertSee('4 of 5 complete')
            ->assertSee('Deploy repository')
            ->assertSee(route('repositories.index'));

        $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'built_at' => now(),
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('onboarding', [
                'provider' => true,
                'server' => true,
                'website' => true,
                'repository' => true,
                'deployment' => true,
            ])
            ->assertDontSee('Get to your first healthy deployment');
    }

    public function test_dashboard_surfaces_a_sanitized_degraded_system_summary(): void
    {
        $this->mock(SystemHealth::class)
            ->shouldReceive('summary')
            ->once()
            ->andReturn([
                'passed' => false,
                'passed_count' => 10,
                'total' => 12,
                'failed_checks' => ['Failed queue jobs', 'Application services'],
                'checked_at' => now()->toIso8601String(),
            ]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('System health needs attention')
            ->assertSee('10 of 12 checks passed')
            ->assertSee('Failing: Failed queue jobs, Application services')
            ->assertSee(route('system-health.index'))
            ->assertDontSee('queue-payload-secret');
    }

    public function test_non_admin_dashboard_uses_public_status_without_private_diagnostics(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;
        $viewer = User::factory()->create();
        $organization->members()->syncWithoutDetaching([$viewer->id => ['role' => 'viewer']]);
        $viewer->update(['current_organization_id' => $organization->id]);

        $this->mock(SystemHealth::class)->shouldNotReceive('summary');
        $this->mock(PublicPlatformStatus::class)
            ->shouldReceive('snapshot')
            ->once()
            ->andReturn([
                'status' => 'degraded',
                'operational' => false,
                'checked_at' => now()->toIso8601String(),
                'components' => [],
            ]);

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Public service-level status without private infrastructure diagnostics.')
            ->assertSee('View public status')
            ->assertSee(route('platform-status.show'))
            ->assertDontSee('View system health')
            ->assertDontSee(route('system-health.index'));
    }

    public function test_dashboard_surfaces_only_the_owners_gallery_updates_without_loading_scripts(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create(['name' => 'Recipe Author']);
        $other = User::factory()->create();
        $publishedAt = now()->subDay();

        $source = $author->recipes()->create([
            'name' => 'Secure web server',
            'description' => 'Harden a web server.',
            'script' => 'source-dashboard-secret',
            'category' => 'security',
            'is_published' => true,
            'published_at' => $publishedAt,
            'gallery_revision_at' => now(),
        ]);
        $copy = $owner->recipes()->create([
            'name' => 'My secure web server',
            'description' => 'Private copy.',
            'script' => 'copy-dashboard-secret',
            'source_recipe_id' => $source->id,
            'source_revision_at' => $publishedAt,
        ]);

        $currentSource = $author->recipes()->create([
            'name' => 'Current recipe',
            'description' => 'Already current.',
            'script' => 'current-source-secret',
            'category' => 'runtime',
            'is_published' => true,
            'published_at' => $publishedAt,
            'gallery_revision_at' => now(),
        ]);
        $owner->recipes()->create([
            'name' => 'Current private copy',
            'description' => 'Already current.',
            'script' => 'current-copy-secret',
            'source_recipe_id' => $currentSource->id,
            'source_revision_at' => $currentSource->gallery_revision_at,
        ]);
        $other->recipes()->create([
            'name' => 'Foreign stale copy',
            'description' => 'Belongs to someone else.',
            'script' => 'foreign-copy-secret',
            'source_recipe_id' => $source->id,
            'source_revision_at' => $publishedAt,
        ]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('recipeUpdateCount', 1)
            ->assertViewHas('recipeUpdates', function ($recipes) use ($source, $copy): bool {
                if ($recipes->count() !== 1 || $recipes->sole()->id !== $source->id) {
                    return false;
                }

                $recipe = $recipes->sole();
                $installed = $recipe->installs->sole();

                return $installed->id === $copy->id
                    && ! array_key_exists('script', $recipe->getAttributes())
                    && ! array_key_exists('description', $recipe->getAttributes())
                    && ! array_key_exists('script', $installed->getAttributes())
                    && ! array_key_exists('description', $installed->getAttributes());
            })
            ->assertSee('Recipe updates')
            ->assertSee('1 installed recipe has a gallery update')
            ->assertSee('Secure web server')
            ->assertSee('My secure web server')
            ->assertSee('Recipe Author')
            ->assertSee(route('gallery.index', ['scope' => 'updates']))
            ->assertSee(route('gallery.compare', ['recipe' => $source, 'copy' => $copy]))
            ->assertSee(route('recipes.edit', $copy))
            ->assertDontSee('Current recipe')
            ->assertDontSee('Foreign stale copy')
            ->assertDontSee('dashboard-secret', false);
    }

    public function test_dashboard_surfaces_only_reports_on_the_owners_published_recipes_without_loading_feedback(): void
    {
        [$owner, $firstReporter, $secondReporter, $otherAuthor] = User::factory()->count(4)->create();
        $owned = $owner->recipes()->create([
            'name' => 'Published image helper',
            'description' => 'Install image tools.',
            'script' => 'published-dashboard-report-secret',
            'category' => 'utilities',
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
        ]);
        foreach ([
            [$firstReporter, 'broken', 'first-private-feedback'],
            [$secondReporter, 'security', 'second-private-feedback'],
        ] as [$reporter, $reason, $details]) {
            $reporter->recipeReports()->create([
                'recipe_id' => $owned->id,
                'reason' => $reason,
                'details' => $details,
            ]);
        }
        $firstReporter->recipeReports()
            ->where('recipe_id', $owned->id)
            ->update(['created_at' => now()->subDays(8)]);
        User::factory()->create()->recipeReports()->create([
            'recipe_id' => $owned->id,
            'reason' => 'security',
            'details' => 'resolved-private-feedback',
            'resolved_at' => now(),
            'created_at' => now()->subDays(30),
        ]);

        $private = $owner->recipes()->create([
            'name' => 'Private reported draft',
            'description' => 'Not published.',
            'script' => 'private-dashboard-report-secret',
        ]);
        $firstReporter->recipeReports()->create([
            'recipe_id' => $private->id,
            'reason' => 'other',
            'details' => 'private-draft-feedback',
        ]);
        $foreign = $otherAuthor->recipes()->create([
            'name' => 'Foreign reported recipe',
            'description' => 'Another contributor owns this.',
            'script' => 'foreign-dashboard-report-secret',
            'category' => 'security',
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
        ]);
        $firstReporter->recipeReports()->create([
            'recipe_id' => $foreign->id,
            'reason' => 'outdated',
            'details' => 'foreign-feedback',
        ]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('communityReportCount', 2)
            ->assertViewHas('communityReportAttention', [
                'security' => 1,
                'stale' => 1,
            ])
            ->assertViewHas('reportedGalleryRecipeCount', 1)
            ->assertViewHas('reportedGalleryRecipes', function ($recipes) use ($owned): bool {
                if ($recipes->count() !== 1) {
                    return false;
                }

                /** @var Recipe $recipe */
                $recipe = $recipes->sole();

                return $recipe->id === $owned->id
                    && $recipe->reports_count === 2
                    && array_diff(array_keys($recipe->getAttributes()), [
                        'id',
                        'user_id',
                        'name',
                        'category',
                        'reports_count',
                    ]) === [];
            })
            ->assertSee('Community recipe feedback')
            ->assertSee('2 community reports need review')
            ->assertSee('1 published recipe affected')
            ->assertSee('All needing review')
            ->assertSee('Security reports')
            ->assertSee('Open at least 7 days')
            ->assertSee('Published image helper')
            ->assertSee(route('gallery.reports.index', ['recipe' => $owned->id]))
            ->assertSee(route('gallery.reports.index'))
            ->assertSee(route('gallery.reports.index', ['reason' => 'security', 'sort' => 'priority']))
            ->assertSee(route('gallery.reports.index', ['age' => '7d', 'sort' => 'oldest']))
            ->assertDontSee('Private reported draft')
            ->assertDontSee('Foreign reported recipe')
            ->assertDontSee('private-feedback', false)
            ->assertDontSee('resolved-private-feedback', false)
            ->assertDontSee('dashboard-report-secret', false)
            ->assertDontSee($firstReporter->email);
    }

    public function test_dashboard_surfaces_only_the_owners_current_failures(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $repository = $this->createResources($user, 'Broken Application');
        $otherRepository = $this->createResources($otherUser, 'Private Failure');

        $repository->provider->forceFill([
            'connection_status' => Provider::CONNECTION_FAILED,
            'connection_checked_at' => now(),
        ])->save();
        $otherRepository->provider->forceFill([
            'connection_status' => Provider::CONNECTION_FAILED,
            'connection_checked_at' => now(),
        ])->save();

        $repository->website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $repository->website->server->update(['provisioning_status' => Server::STATUS_FAILED]);
        $failedBuild = Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_FAILED,
            'finished_at' => now(),
        ]);
        $otherRepository->website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $otherRepository->website->server->update(['provisioning_status' => Server::STATUS_FAILED]);
        Build::create([
            'repository_id' => $otherRepository->id,
            'status' => Build::STATUS_FAILED,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('Needs attention')
            ->assertSee('4 active issues')
            ->assertSee('Health check failing')
            ->assertSee('Provisioning failed')
            ->assertSee('Latest deployment failed')
            ->assertSee('Connection failed')
            ->assertSee(route('websites.show', $repository->website))
            ->assertSee(route('servers.show', $repository->website->server))
            ->assertSee(route('builds.show', $failedBuild))
            ->assertSee(route('providers.show', $repository->provider))
            ->assertSee(route('providers.index', ['connection' => Provider::CONNECTION_FAILED]))
            ->assertDontSee('Private Failure');
    }

    public function test_a_successful_latest_deployment_and_recovered_resources_clear_attention(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $repository = $this->createResources($user, 'Recovered Application');
        Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_FAILED,
        ]);
        Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_SUCCEEDED,
        ]);
        $repository->website->update([
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_HEALTHY,
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository->website->server->update(['provisioning_status' => Server::STATUS_ACTIVE]);
        $repository->provider->forceFill([
            'connection_status' => Provider::CONNECTION_HEALTHY,
            'connection_checked_at' => now(),
        ])->save();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('No active failures')
            ->assertSee('No unhealthy websites, provisioning failures, failed latest deployments, or provider connection failures.')
            ->assertDontSee('Needs attention');
    }

    public function test_dashboard_provider_health_summary_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $healthy = $this->provider($owner, 'Healthy Provider', Provider::CONNECTION_HEALTHY);
        $failed = $this->provider($owner, 'Failed Provider', Provider::CONNECTION_FAILED);
        $this->provider($owner, 'Unchecked Provider');
        $other = User::factory()->create();
        $this->provider($other, 'Foreign Failed Provider', Provider::CONNECTION_FAILED);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('providerHealthCounts', [
                'healthy' => 1,
                'failed' => 1,
                'unchecked' => 1,
            ])
            ->assertViewHas('attentionCounts', fn (array $counts): bool => $counts['providers'] === 1)
            ->assertViewHas('attentionProviders', fn ($providers): bool => $providers->count() === 1 && $providers->sole()->is($failed))
            ->assertSee('Provider credential health')
            ->assertSee(route('providers.index', ['connection' => Provider::CONNECTION_HEALTHY]))
            ->assertSee(route('providers.index', ['connection' => Provider::CONNECTION_FAILED]))
            ->assertSee(route('providers.index', ['connection' => Provider::CONNECTION_UNCHECKED]))
            ->assertSee(route('providers.show', $failed))
            ->assertDontSee(route('providers.show', $healthy))
            ->assertDontSee('Foreign Failed Provider');
    }

    public function test_dashboard_summarizes_only_the_owners_active_deployments(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $repository = $this->createResources($owner, 'Active Application');
        $queued = Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_QUEUED,
        ]);
        $running = Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_RUNNING,
        ]);
        Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_SUCCEEDED,
        ]);
        $other = User::factory()->create();
        $otherRepository = $this->createResources($other, 'Foreign Active Application');
        $foreign = Build::create([
            'repository_id' => $otherRepository->id,
            'status' => Build::STATUS_DEPLOYING,
        ]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('activeDeploymentCounts', [
                Build::STATUS_AWAITING_APPROVAL => 0,
                Build::STATUS_QUEUED => 1,
                Build::STATUS_DEPLOYING => 0,
                Build::STATUS_RUNNING => 1,
                Build::STATUS_TIMING_OUT => 0,
            ])
            ->assertViewHas('activeDeployments', fn ($builds): bool => $builds->count() === 2)
            ->assertSee('Active deployments')
            ->assertSee('2 deployments are in progress')
            ->assertSee(route('builds.show', $queued))
            ->assertSee(route('builds.show', $running))
            ->assertSee(route('builds.index', ['active' => 1]))
            ->assertSee('View active deployments')
            ->assertDontSee(route('builds.show', $foreign))
            ->assertDontSee('Foreign Active Application');
    }

    public function test_active_deployment_panel_limits_rows_and_links_to_full_history(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $repository = $this->createResources($owner, 'Busy Application');
        foreach (range(1, 6) as $position) {
            Build::create([
                'repository_id' => $repository->id,
                'status' => Build::STATUS_QUEUED,
                'created_at' => now()->addSeconds($position),
            ]);
        }

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('activeDeployments', fn ($builds): bool => $builds->count() === 5)
            ->assertSee('6 deployments are in progress')
            ->assertSee('1 more active deployment')
            ->assertSee(route('builds.index', ['active' => 1]));
    }

    public function test_dashboard_summarizes_active_commands_without_loading_encrypted_content(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $repository = $this->createResources($owner, 'Command Application');
        $server = $repository->website->server;
        foreach (range(1, 6) as $position) {
            $server->commandExecutions()->create([
                'user_id' => $owner->id,
                'command' => "dashboard-sensitive-command-{$position}",
                'output' => "dashboard-sensitive-output-{$position}",
                'status' => $position % 2 === 0
                    ? ServerCommandExecution::STATUS_RUNNING
                    : ServerCommandExecution::STATUS_QUEUED,
            ]);
        }
        $server->commandExecutions()->create([
            'user_id' => $owner->id,
            'command' => 'completed-dashboard-command',
            'status' => ServerCommandExecution::STATUS_SUCCEEDED,
        ]);
        $other = User::factory()->create();
        $otherRepository = $this->createResources($other, 'Foreign Command Application');
        $otherRepository->website->server->commandExecutions()->create([
            'user_id' => $other->id,
            'command' => 'foreign-dashboard-command',
            'status' => ServerCommandExecution::STATUS_RUNNING,
        ]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('activeCommandCounts', [
                ServerCommandExecution::STATUS_QUEUED => 3,
                ServerCommandExecution::STATUS_RUNNING => 3,
            ])
            ->assertViewHas('activeCommands', function ($executions): bool {
                return $executions->count() === 5
                    && $executions->every(fn (ServerCommandExecution $execution): bool => ! array_key_exists('command', $execution->getAttributes())
                        && ! array_key_exists('output', $execution->getAttributes()));
            })
            ->assertSee('Active server commands')
            ->assertSee('6 commands are active')
            ->assertSee('1 more active command is available in server history')
            ->assertSee(route('servers.commands.index', [
                'server' => $server,
                'status' => ServerCommandExecution::STATUS_RUNNING,
            ]))
            ->assertSee(route('commands.index', ['active' => 1]))
            ->assertSee('Open Command Center')
            ->assertDontSee('dashboard-sensitive-command', false)
            ->assertDontSee('dashboard-sensitive-output', false)
            ->assertDontSee('foreign-dashboard-command', false)
            ->assertDontSee('Foreign Command Application');
    }

    public function test_dashboard_summarizes_recent_webhook_deliveries_without_loading_commit_content(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $repository = $this->createResources($owner, 'Webhook Application');
        $statuses = [
            RepositoryWebhookDelivery::STATUS_QUEUED,
            RepositoryWebhookDelivery::STATUS_PENDING,
            RepositoryWebhookDelivery::STATUS_UNAVAILABLE,
            RepositoryWebhookDelivery::STATUS_SUPERSEDED,
            RepositoryWebhookDelivery::STATUS_RECEIVED,
            RepositoryWebhookDelivery::STATUS_QUEUED,
        ];

        foreach ($statuses as $position => $status) {
            $repository->webhookDeliveries()->create([
                'delivery_id' => "owner-sensitive-delivery-{$position}",
                'revision' => str_repeat((string) ($position + 1), 40),
                'commit_message' => "owner-sensitive-commit-{$position}",
                'status' => $status,
                'created_at' => now()->subMinutes($position),
            ]);
        }

        $repository->webhookDeliveries()->create([
            'delivery_id' => 'old-sensitive-delivery',
            'commit_message' => 'old-sensitive-commit',
            'status' => RepositoryWebhookDelivery::STATUS_UNAVAILABLE,
            'created_at' => now()->subDays(2),
        ]);

        $other = User::factory()->create();
        $foreignRepository = $this->createResources($other, 'Foreign Webhook Application');
        $foreignRepository->webhookDeliveries()->create([
            'delivery_id' => 'foreign-sensitive-delivery',
            'commit_message' => 'foreign-sensitive-commit',
            'status' => RepositoryWebhookDelivery::STATUS_PENDING,
        ]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('webhookDeliveryCounts', [
                RepositoryWebhookDelivery::STATUS_QUEUED => 2,
                RepositoryWebhookDelivery::STATUS_PENDING => 1,
                RepositoryWebhookDelivery::STATUS_UNAVAILABLE => 1,
                RepositoryWebhookDelivery::STATUS_SUPERSEDED => 1,
                RepositoryWebhookDelivery::STATUS_RECEIVED => 1,
            ])
            ->assertViewHas('recentWebhookDeliveries', function ($deliveries): bool {
                return $deliveries->count() === 5
                    && $deliveries->every(fn (RepositoryWebhookDelivery $delivery): bool => ! array_key_exists('delivery_id', $delivery->getAttributes())
                        && ! array_key_exists('revision', $delivery->getAttributes())
                        && ! array_key_exists('commit_message', $delivery->getAttributes()));
            })
            ->assertSee('Webhook deliveries')
            ->assertSee('6 deliveries received in the last 24 hours')
            ->assertSee('1 more delivery is available in repository history')
            ->assertSee(route('repositories.show', [
                'repository' => $repository,
                'delivery_status' => RepositoryWebhookDelivery::STATUS_QUEUED,
            ]).'#webhook-deliveries')
            ->assertSee(route('activity.index', ['category' => 'deployment']))
            ->assertDontSee('owner-sensitive-delivery', false)
            ->assertDontSee('owner-sensitive-commit', false)
            ->assertDontSee('old-sensitive', false)
            ->assertDontSee('foreign-sensitive', false)
            ->assertDontSee('Foreign Webhook Application');
    }

    public function test_dashboard_summarizes_owner_provisioning_without_loading_credentials_or_environment(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $serverStatuses = [
            Server::STATUS_QUEUED,
            Server::STATUS_WAITING_FOR_IP,
            Server::STATUS_PROVISIONING,
        ];
        $waitingServer = null;

        foreach ($serverStatuses as $position => $status) {
            $repository = $this->createResources($owner, "Provisioning Server {$position}");
            $server = $repository->website->server;
            $server->update([
                'provisioning_status' => $status,
                'password' => "sensitive-server-password-{$position}",
                'mysql_root_password' => "sensitive-mysql-password-{$position}",
                'ssh_private_key' => "sensitive-private-key-{$position}",
            ]);

            if ($status === Server::STATUS_WAITING_FOR_IP) {
                $waitingServer = $server;
            }
        }

        $websiteStatuses = [
            Website::STATUS_QUEUED,
            Website::STATUS_PROVISIONING,
            Website::STATUS_QUEUED,
        ];
        $latestWebsite = null;

        foreach ($websiteStatuses as $position => $status) {
            $repository = $this->createResources($owner, "Provisioning Website {$position}");
            $latestWebsite = $repository->website;
            $latestWebsite->update([
                'provisioning_status' => $status,
                'environment' => "SENSITIVE_ENVIRONMENT={$position}",
                'database_password' => "sensitive-database-password-{$position}",
            ]);
        }

        $waitingServer->forceFill(['created_at' => now()->addMinutes(2)])->save();
        $latestWebsite->forceFill(['created_at' => now()->addMinute()])->save();

        $other = User::factory()->create();
        $foreignRepository = $this->createResources($other, 'Foreign Provisioning Resource');
        $foreignRepository->website->server->update(['provisioning_status' => Server::STATUS_PROVISIONING]);
        $foreignRepository->website->update(['provisioning_status' => Website::STATUS_PROVISIONING]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertViewHas('provisioningCounts', [
                'servers' => 3,
                'websites' => 3,
            ])
            ->assertViewHas('provisioningResources', function ($resources): bool {
                return $resources->count() === 5
                    && $resources->every(function (Server|Website $resource): bool {
                        $safeAttributes = ['id', 'user_id', 'name', 'provisioning_status', 'created_at'];
                        if ($resource instanceof Server) {
                            $safeAttributes[] = 'display_name';
                        }

                        return array_diff(array_keys($resource->getAttributes()), $safeAttributes) === [];
                    });
            })
            ->assertSee('Infrastructure provisioning')
            ->assertSee('6 resources are being prepared')
            ->assertSee('1 more resource is provisioning')
            ->assertSee(route('servers.index', ['provisioning' => 1]))
            ->assertSee(route('websites.index', ['provisioning' => 1]))
            ->assertSee('View provisioning servers')
            ->assertSee('View provisioning websites')
            ->assertSee(route('websites.show', $latestWebsite))
            ->assertSee('waiting for ip')
            ->assertDontSee('sensitive-server-password', false)
            ->assertDontSee('sensitive-mysql-password', false)
            ->assertDontSee('sensitive-private-key', false)
            ->assertDontSee('SENSITIVE_ENVIRONMENT', false)
            ->assertDontSee('sensitive-database-password', false)
            ->assertDontSee('Foreign Provisioning Resource');
    }

    public function test_user_can_customize_visible_dashboard_widgets(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('dashboard.preferences.update'), [
            'widgets' => ['stats', 'status'],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(['stats', 'status'], $user->fresh()->preferences['dashboard_widgets']);
        $this->get(route('dashboard'))->assertOk()->assertSee('Platform status')->assertDontSee('Provider credential health');
    }

    private function createResources(User $user, string $name)
    {
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => 'github',
            'token' => 'secret',
            'description' => 'Git provider',
        ]);
        $server = $user->servers()->create([
            'name' => "$name Server",
            'provider_id' => $provider->id,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => str($name)->slug().'.test',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => "$name Repository",
            'url' => 'github.com/example/project.git',
            'description' => 'Repository',
        ]);
    }

    private function provider(User $user, string $name, ?string $status = null): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'secret',
            'description' => 'Dashboard provider',
            'connection_status' => $status,
            'connection_checked_at' => $status === null ? null : now(),
        ]);
    }
}
