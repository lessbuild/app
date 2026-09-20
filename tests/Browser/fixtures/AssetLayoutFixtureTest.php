<?php

use App\Models\Build;
use App\Models\Recipe;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Models\Website;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
use App\Services\IncidentNotifier;
use App\Services\OperationalDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/** Render real Blade pages against an isolated SQLite database for asset checks. */
class AssetLayoutFixtureTest extends TestCase
{
    use RefreshDatabase;

    /** Export public and authenticated pages without accessing saved accounts. */
    public function test_export_layouts(): void
    {
        $directory = getenv('BROWSER_FIXTURE_DIRECTORY');
        $this->assertNotFalse($directory);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        // Keep absolute route-generated modal URLs same-origin with the fixture browser host.
        config(['app.url' => 'http://buildpusher.test']);
        url()->forceRootUrl('http://buildpusher.test');
        File::ensureDirectoryExists($directory);

        foreach (['landing' => '/', 'login' => '/login', 'pricing' => '/pricing'] as $name => $url) {
            File::put($directory.'/'.$name.'.html', $this->renderPage($url)->assertOk()->getContent());
        }

        $owner = User::factory()->create(['name' => 'Layout fixture owner']);
        $this->actingAs($owner);
        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->andReturn([
                ['name' => 'Application key', 'passed' => true, 'detail' => 'Configured'],
                ['name' => 'Queue worker', 'passed' => true, 'detail' => 'Ready'],
            ]);
        $dashboardProvider = $owner->providers()->create([
            'name' => 'Dashboard provider', 'provider' => 'github', 'token' => 'dashboard-token', 'description' => 'Dashboard fixture provider',
        ]);
        $dashboardServer = $owner->servers()->create([
            'provider_id' => $dashboardProvider->id, 'name' => 'Dashboard server', 'type' => 'app',
            'region' => 'nyc1', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $owner->servers()->create([
            'provider_id' => $dashboardProvider->id, 'name' => 'Provisioning fixture server', 'type' => 'app',
            'region' => 'nyc1', 'provisioning_status' => Server::STATUS_PROVISIONING,
        ]);
        $dashboardWebsite = $owner->websites()->create([
            'server_id' => $dashboardServer->id, 'name' => 'Dashboard website', 'url' => 'dashboard.test',
            'description' => 'Dashboard fixture website', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $dashboardRepository = $owner->repositories()->create([
            'provider_id' => $dashboardProvider->id, 'website_id' => $dashboardWebsite->id,
            'name' => 'Dashboard repository', 'url' => 'github.com/example/dashboard.git', 'branch' => 'main', 'description' => 'Dashboard fixture repository',
        ]);
        $dashboardRepository->builds()->create([
            'status' => Build::STATUS_RUNNING, 'trigger_source' => Build::TRIGGER_MANUAL,
            'revision' => str_repeat('d', 40), 'started_at' => now(),
        ]);
        $dashboardServer->commandExecutions()->create([
            'user_id' => $owner->id,
            'command' => 'fixture-sensitive-command',
            'output' => 'fixture-sensitive-output',
            'status' => ServerCommandExecution::STATUS_RUNNING,
        ]);
        $dashboardRepository->webhookDeliveries()->create([
            'delivery_id' => 'fixture-webhook-delivery',
            'status' => RepositoryWebhookDelivery::STATUS_PENDING,
            'created_at' => now(),
        ]);
        File::put($directory.'/dashboard.html', $this->renderPage(route('dashboard'))->assertOk()
            ->assertSee('data-modal-trigger="dashboard-activity-dialog"', false)
            ->assertSee('data-modal-trigger="dashboard-system-health-dialog"', false)
            ->assertSee('data-modal-trigger="dashboard-active-commands-dialog"', false)
            ->assertSee('data-modal-trigger="dashboard-provisioning-dialog"', false)
            ->assertSee('data-modal-trigger="dashboard-webhook-activity-dialog"', false)->getContent());
        File::put($directory.'/dashboard-activity-dialog.html', $this->renderPage(route('dashboard', ['dialog' => 'dashboard-activity']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/dashboard-active-deployments-dialog.html', $this->renderPage(route('dashboard', ['dialog' => 'active-deployments']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/dashboard-system-health-dialog.html', $this->renderPage(route('dashboard', ['dialog' => 'system-health']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/dashboard-active-commands-dialog.html', $this->renderPage(route('dashboard', ['dialog' => 'active-commands']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/dashboard-webhook-activity-dialog.html', $this->renderPage(route('dashboard', ['dialog' => 'webhook-activity']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/dashboard-provisioning-dialog.html', $this->renderPage(route('dashboard', ['dialog' => 'provisioning']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)
            ->assertSee('Provisioning fixture server')->getContent());
        $organizationMember = User::factory()->create(['name' => 'Organization fixture member', 'email' => 'member@fixture.test']);
        $owner->currentOrganization->members()->attach($organizationMember, ['role' => 'developer']);
        File::put($directory.'/organization.html', $this->renderPage(route('organizations.index'))->assertOk()->getContent());
        File::put($directory.'/organization-dialog.html', $this->renderPage(route('organizations.index', ['dialog' => 'invite-member']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/organization-member-role-dialog.html', $this->renderPage(route('organizations.index', [
            'dialog' => 'member-role-'.$organizationMember->id,
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)
            ->assertSee('Organization fixture member')->getContent());
        File::put($directory.'/feedback.html', $this->renderPage(route('feedback.index'))->assertOk()
            ->assertSee('Send private feedback')->getContent());
        File::put($directory.'/feedback-dialog.html', $this->renderPage(route('feedback.index', ['dialog' => 'compose-feedback']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/notifications.html', $this->renderPage(route('notifications.index'))->assertOk()
            ->assertSee('Save current')->getContent());
        File::put($directory.'/notifications-dialog.html', $this->renderPage(route('notifications.index', ['dialog' => 'save-filter']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/account.html', $this->renderPage(route('account.index'))->assertOk()
            ->assertSee('data-modal-trigger="account-profile-dialog"', false)
            ->assertSee('data-modal-trigger="account-sign-in-history-dialog"', false)
            ->assertSee('data-modal-trigger="account-audit-dialog"', false)->getContent());
        File::put($directory.'/account-profile-dialog.html', $this->renderPage(route('account.index', ['dialog' => 'account-profile-dialog']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/account-sign-in-history-dialog.html', $this->renderPage(route('account.index', ['dialog' => 'account-sign-in-history-dialog']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/account-audit-dialog.html', $this->renderPage(route('account.index', ['dialog' => 'account-audit-dialog']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/sign-in-history.html', $this->renderPage(route('account.sign-ins.index'))->assertOk()
            ->assertSee('No sign-in history yet.')->getContent());
        File::put($directory.'/sign-in-history-content.html', $this->renderPage(route('account.sign-ins.index', [
            'fragment' => 'sign-in-history',
        ]))->assertOk()->assertSee('data-modal-fragment-form', false)->getContent());
        File::put($directory.'/account-audit-content.html', $this->renderPage(route('activity.index', [
            'category' => 'account',
            'fragment' => 'account-audit',
        ]))->assertOk()->assertSee('data-activity-audit-content', false)->getContent());
        File::put($directory.'/workspace-activity-content.html', $this->renderPage(route('activity.index', [
            'fragment' => 'workspace-activity',
        ]))->assertOk()->assertSee('data-activity-history-content', false)->getContent());
        File::put($directory.'/dashboard-active-deployments.html', $this->renderPage(route('builds.index', [
            'active' => 1,
            'fragment' => 'deployment-history',
        ]))->assertOk()->assertSee('data-build-card', false)->getContent());
        File::put($directory.'/system-health-content.html', $this->renderPage(route('system-health.index', [
            'fragment' => 'system-health',
        ]))->assertOk()->assertSee('id="system-health-insights"', false)->getContent());
        File::put($directory.'/dashboard-active-commands.html', $this->renderPage(route('commands.index', [
            'active' => 1,
            'fragment' => 'active-command-history',
        ]))->assertOk()
            ->assertSee('data-command-history-content', false)
            ->assertDontSee('fixture-sensitive-command')->getContent());
        File::put($directory.'/deployment-activity-content.html', $this->renderPage(route('activity.index', [
            'category' => 'deployment',
            'fragment' => 'workspace-activity',
        ]))->assertOk()->assertSee('data-activity-history-content', false)->getContent());
        $galleryAuthor = User::factory()->create(['name' => 'Gallery fixture author']);
        $galleryRecipe = $galleryAuthor->recipes()->create([
            'name' => 'Gallery fixture recipe',
            'description' => 'A published recipe for modal browser coverage.',
            'script' => 'echo gallery-fixture',
            'category' => Recipe::CATEGORIES[0],
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
        ]);
        File::put($directory.'/gallery.html', $this->renderPage(route('gallery.show', $galleryRecipe))->assertOk()
            ->assertSee('Report issue')->getContent());
        File::put($directory.'/gallery-index.html', $this->renderPage(route('gallery.index'))->assertOk()
            ->assertSee('Publish a Recipe')
            ->assertSee('data-modal-trigger="gallery-inspect-script-'.$galleryRecipe->id.'"', false)
            ->assertDontSee('echo gallery-fixture', false)->getContent());
        File::put($directory.'/gallery-index-publish-dialog.html', $this->renderPage(route('gallery.index', ['dialog' => 'publish-recipe']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/gallery-index-inspect-dialog.html', $this->renderPage(route('gallery.index', [
            'dialog' => 'inspect-script-'.$galleryRecipe->id,
        ]))->assertOk()
            ->assertSee('data-modal-initial-open="true"', false)
            ->assertSee('echo gallery-fixture')->getContent());
        File::put($directory.'/gallery-script.html', $this->renderPage(route('gallery.script', $galleryRecipe))
            ->assertOk()->assertSee('echo gallery-fixture')->getContent());
        File::put($directory.'/gallery-dialog.html', $this->renderPage(route('gallery.show', [
            'recipe' => $galleryRecipe,
            'dialog' => 'report',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        $galleryReporter = User::factory()->create(['name' => 'Gallery fixture reporter']);
        $galleryReporter->recipeReports()->create([
            'recipe_id' => $galleryRecipe->id,
            'reason' => 'broken',
            'details' => 'Fixture contributor report.',
        ]);
        User::factory()->create(['name' => 'Gallery fixture reporter two'])->recipeReports()->create([
            'recipe_id' => $galleryRecipe->id,
            'reason' => 'security',
            'resolved_at' => now(),
            'resolution_note' => 'Fixture resolution note.',
        ]);
        $this->actingAs($galleryAuthor);
        File::put($directory.'/gallery-review.html', $this->renderPage(route('gallery.show', $galleryRecipe))->assertOk()
            ->assertSee('data-modal-trigger="gallery-report-resolution-', false)
            ->assertSee('Mark Resolved')->assertSee('Update Resolution Note')->getContent());
        $this->actingAs($owner);
        $galleryHistoryReport = $owner->recipeReports()->create([
            'recipe_id' => $galleryRecipe->id,
            'reason' => 'broken',
            'details' => 'Fixture private report details.',
            'resolved_at' => now(),
            'resolution_note' => 'Fixture contributor resolution.',
        ]);
        File::put($directory.'/gallery-my-reports.html', $this->renderPage(route('gallery.reports.mine'))->assertOk()
            ->assertSee('data-modal-trigger="gallery-report-status-dialog"', false)->getContent());
        File::put($directory.'/gallery-my-reports-dialog.html', $this->renderPage(route('gallery.reports.mine', [
            'dialog' => 'report-status-'.$galleryHistoryReport->id,
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/gallery-report-status-content.html', $this->renderPage(route('gallery.report.status', [
            'report' => $galleryHistoryReport,
            'fragment' => 'report-status',
        ]))->assertOk()->assertSee('Fixture private report details.')->getContent());
        $entitlementEnforcement = config('billing.enforce_entitlements');
        config(['billing.enforce_entitlements' => true]);
        File::put($directory.'/provider-create.html', $this->renderPage(route('providers.create'))->assertOk()->getContent());
        File::put($directory.'/providers.html', $this->renderPage(route('providers.index'))->assertOk()
            ->assertSee('data-modal-trigger="provider-create-dialog"', false)->getContent());
        File::put($directory.'/providers-dialog.html', $this->renderPage(route('providers.index', ['dialog' => 'create-provider']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        config(['billing.enforce_entitlements' => $entitlementEnforcement]);

        $project = $owner->currentOrganization->projects()->create([
            'name' => 'A deliberately long layout fixture application name', 'slug' => 'layout-fixture', 'created_by' => $owner->id,
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
        ]);
        $scheduledTask = $environment->scheduledTasks()->create([
            'created_by' => $owner->id,
            'name' => 'Warm cache',
            'command' => 'php artisan cache:warm',
            'cron_expression' => '0 * * * *',
            'timezone' => 'UTC',
            'timeout_seconds' => 300,
            'without_overlapping' => true,
            'alert_on_failure' => true,
            'is_enabled' => true,
        ]);
        $scheduledTaskRun = $scheduledTask->runs()->create([
            'status' => 'succeeded',
            'output' => 'fixture scheduled task output',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_ms' => 800,
        ]);
        File::put($directory.'/automation.html', $this->renderPage(route('automation.index'))->assertOk()
            ->assertSee('Automate routine release work')
            ->assertSee('data-modal-trigger="automation-token-dialog"', false)
            ->assertSee('data-modal-trigger="automation-schedule-dialog-', false)
            ->assertSee('data-modal-trigger="automation-task-dialog-', false)
            ->assertSee('data-modal-trigger="automation-task-run-dialog"', false)
            ->getContent());
        File::put($directory.'/automation-dialog.html', $this->renderPage(route('automation.index', ['dialog' => 'create-token']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/automation-run-dialog.html', $this->renderPage(route('automation.index', ['dialog' => 'scheduled-task-run-'.$scheduledTaskRun->id]))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/automation-task-run-output.html', $this->renderPage(route('automation.task-runs.output', [
            'run' => $scheduledTaskRun,
            'fragment' => 'scheduled-task-output',
        ]))->assertOk()->assertSee('fixture scheduled task output')->getContent());
        File::put($directory.'/projects.html', $this->renderPage(route('projects.index'))->assertOk()
            ->assertSee('A deliberately long layout fixture application name')->getContent());
        File::put($directory.'/projects-dialog.html', $this->renderPage(route('projects.index', ['dialog' => 'create-application']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        $provider = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => 'github', 'token' => 'fixture-token', 'description' => 'Test',
        ]);
        File::put($directory.'/provider-show.html', $this->renderPage(route('providers.show', $provider))->assertOk()
            ->assertSee('data-modal-trigger="provider-edit-dialog"', false)->getContent());
        File::put($directory.'/provider-connection-checks.html', $this->renderPage(route('providers.connection-checks.index', [
            'provider' => $provider,
            'fragment' => 'provider-connection-checks',
        ]))->assertOk()->assertSee('data-modal-fragment-form', false)->getContent());
        File::put($directory.'/provider-show-edit-dialog.html', $this->renderPage(route('providers.show', [
            'provider' => $provider,
            'dialog' => 'edit-provider',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/provider-edit-content.html', $this->renderPage(route('providers.edit', [
            'provider' => $provider,
            'dialog' => 'edit-provider',
            'fragment' => 1,
            'return_to' => route('providers.show', $provider),
        ]))->assertOk()->assertSee('<form', false)->getContent());
        $server = $owner->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Server',
            'type' => 'app',
            'region' => 'nyc1',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test',
            'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        File::put($directory.'/servers.html', $this->renderPage(route('servers.index'))->assertOk()
            ->assertSee('data-modal-trigger="server-create-dialog"', false)->getContent());
        File::put($directory.'/servers-dialog.html', $this->renderPage(route('servers.index', ['dialog' => 'create-server']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/server-show.html', $this->renderPage(route('servers.show', $server))->assertOk()
            ->assertSee('data-modal-trigger="server-display-name-dialog"', false)
            ->assertSee('data-modal-trigger="server-command-history-dialog"', false)->getContent());
        $server->commandExecutions()->create([
            'user_id' => $owner->id,
            'command' => 'uname -a',
            'status' => ServerCommandExecution::STATUS_SUCCEEDED,
            'output' => 'fixture command output',
            'finished_at' => now(),
        ]);
        File::put($directory.'/server-command-history.html', $this->renderPage(route('servers.commands.index', [
            'server' => $server,
            'fragment' => 'server-command-history',
        ]))->assertOk()->assertSee('data-command-execution', false)->getContent());
        File::put($directory.'/server-show-edit-dialog.html', $this->renderPage(route('servers.show', [
            'server' => $server,
            'dialog' => 'edit-display-name',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/websites.html', $this->renderPage(route('websites.index'))->assertOk()
            ->assertSee('data-modal-trigger="website-create-dialog"', false)->getContent());
        File::put($directory.'/websites-dialog.html', $this->renderPage(route('websites.index', ['dialog' => 'create-website']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/website-show.html', $this->renderPage(route('websites.show', $website))->assertOk()
            ->assertSee('data-modal-trigger="website-edit-dialog"', false)
            ->assertSee('data-modal-trigger="website-log-retention-dialog"', false)
            ->assertSee('data-modal-trigger="website-health-checks-dialog"', false)
            ->assertSee('data-modal-trigger="website-deployment-history-dialog"', false)
            ->assertDontSee('APP_ENV=production')->getContent());
        File::put($directory.'/website-health-checks.html', $this->renderPage(route('websites.health-checks.index', [
            'website' => $website,
            'fragment' => 'website-health-checks',
        ]))->assertOk()->assertSee('data-modal-fragment-form', false)->getContent());
        File::put($directory.'/website-show-log-retention-dialog.html', $this->renderPage(route('websites.show', [
            'website' => $website,
            'dialog' => 'website-log-retention',
        ]))->assertOk()
            ->assertSee('data-modal-initial-open="true"', false)
            ->getContent());
        File::put($directory.'/website-show-edit-dialog.html', $this->renderPage(route('websites.show', [
            'website' => $website,
            'dialog' => 'edit-website',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/website-edit-content.html', $this->renderPage(route('websites.edit', [
            'website' => $website,
            'dialog' => 'edit-website',
            'fragment' => 1,
            'return_to' => route('websites.show', $website),
        ]))->assertOk()->assertSee('<form', false)->getContent());
        $destination = $owner->currentOrganization->backupDestinations()->create([
            'created_by' => $owner->id,
            'name' => 'Fixture storage',
            'endpoint' => 'https://storage.example.test',
            'bucket' => 'buildpusher-fixture',
            'region' => 'auto',
            'access_key' => 'fixture-access',
            'secret_key' => 'fixture-secret',
            'repository_password' => 'fixture-repository-password',
            'path_prefix' => 'fixture',
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App',
            'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test',
        ]);
        $repository->update([
            'webhook_enabled' => true,
            'auto_deploy_include_paths' => ['apps/**'],
        ]);
        File::put($directory.'/repositories.html', $this->renderPage(route('repositories.index'))->assertOk()
            ->assertSee('data-modal-trigger="repository-create-dialog"', false)
            ->assertSee('data-modal-trigger="repository-impact-preview-dialog"', false)
            ->getContent());
        File::put($directory.'/repositories-dialog.html', $this->renderPage(route('repositories.index', ['dialog' => 'create-repository']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/repositories-impact-preview-dialog.html', $this->renderPage(route('repositories.index', ['dialog' => 'impact-preview']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/repositories-impact-preview-content.html', $this->renderPage(route('repositories.impact-preview', [
            'changed_paths' => 'apps/app.php',
            'fragment' => 'repository-impact-preview',
        ]))->assertOk()
            ->assertSee('data-repository-impact-preview-content', false)
            ->assertSee('App')
            ->assertSee('Affected')->getContent());
        File::put($directory.'/repository-show.html', $this->renderPage(route('repositories.show', $repository))->assertOk()
            ->assertSee('data-modal-trigger="repository-edit-dialog"', false)->getContent());
        File::put($directory.'/repository-show-webhook-dialog.html', $this->renderPage(route('repositories.show', [
            'repository' => $repository,
            'dialog' => 'repository-webhook-settings',
        ]))->assertOk()
            ->assertSee('data-modal-initial-open="true"', false)
            ->getContent());
        File::put($directory.'/repository-show-edit-dialog.html', $this->renderPage(route('repositories.show', [
            'repository' => $repository,
            'dialog' => 'edit-repository',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/repository-edit-content.html', $this->renderPage(route('repositories.edit', [
            'repository' => $repository,
            'dialog' => 'edit-repository',
            'fragment' => 1,
            'return_to' => route('repositories.show', $repository),
        ]))->assertOk()->assertSee('<form', false)->getContent());
        $recipe = $owner->recipes()->create([
            'name' => 'Fixture recipe',
            'description' => 'Recipe used by the modal browser fixture.',
            'script' => 'echo fixture-recipe',
        ]);
        File::put($directory.'/recipes.html', $this->renderPage(route('recipes.index'))->assertOk()
            ->assertSee('data-modal-trigger="recipe-create-dialog"', false)
            ->assertDontSee('echo fixture-recipe', false)->getContent());
        File::put($directory.'/recipes-dialog.html', $this->renderPage(route('recipes.index', ['dialog' => 'create-recipe']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/recipes-edit-dialog.html', $this->renderPage(route('recipes.index', [
            'dialog' => 'edit-recipe-'.$recipe->id,
        ]))->assertOk()
            ->assertSee('data-modal-initial-open="true"', false)
            ->assertSee('echo fixture-recipe', false)->getContent());
        File::put($directory.'/recipe-edit-content.html', $this->renderPage(route('recipes.edit', [
            'recipe' => $recipe,
            'dialog' => 'edit-recipe-'.$recipe->id,
            'fragment' => 1,
            'return_to' => route('recipes.index'),
        ]))->assertOk()->assertSee('<form', false)->getContent());
        $project->environments()->where('type', 'production')->firstOrFail()->update([
            'server_id' => $server->id,
            'website_id' => $website->id,
        ]);
        File::put($directory.'/project-detail.html', $this->renderPage(route('projects.show', $project))->assertOk()
            ->assertSee('data-modal-trigger="add-environment-dialog"', false)
            ->assertSee('data-modal-trigger="environment-settings-dialog-', false)
            ->assertSee('data-modal-trigger="environment-deployment-controls-dialog-', false)
            ->assertSee('data-modal-trigger="environment-variable-dialog-', false)
            ->assertSee('data-modal-trigger="environment-process-dialog-', false)
            ->assertSee('data-modal-trigger="environment-resource-dialog-', false)
            ->assertSee('data-modal-trigger="project-preview-settings-dialog"', false)
            ->assertSee('data-modal-trigger="application-configuration-dialog"', false)
            ->getContent());
        File::put($directory.'/configuration-dialog.html', $this->renderPage(route('projects.configuration.dialog', $project))
            ->assertOk()
            ->assertSee('Create a review')
            ->assertDontSee('<html', false)
            ->getContent());
        $review = app(ApplicationConfigurationReviews::class)->create($project, $owner,
            "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n      build_command: fixture-private-command\n    deploy:\n      repository: app\n",
            ['placements' => ['site' => $website->id], 'repositories' => ['app' => $repository->id]],
        );
        $reviewUrl = route('projects.configuration.review', [$project, $review]);
        File::put($directory.'/configuration-review.html', $this->renderPage($reviewUrl)->assertOk()
            ->assertSee('Apply reviewed configuration')->assertDontSee('fixture-private-command')->getContent());
        app(ApplicationConfigurationReconciler::class)->apply($review, $owner);
        File::put($directory.'/configuration-receipt.html', $this->renderPage($reviewUrl)->assertOk()
            ->assertSee('Cancel pending deployment')->assertDontSee('fixture-private-command')->getContent());
        File::put($directory.'/configuration-create.html', $this->renderPage(route('projects.configuration.create', $project))
            ->assertOk()->assertSee('Recent application receipts')->getContent());

        $previousBuild = $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'revision' => str_repeat('b', 40),
            'failure_message' => 'Fixture baseline failure',
            'started_at' => now()->subMinutes(6),
            'finished_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(6),
        ]);
        $build = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'setup_stage' => 15,
            'revision' => str_repeat('a', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
        File::put($directory.'/website-deployment-history.html', $this->renderPage(route('builds.index', [
            'website_id' => $website->id,
            'fragment' => 'deployment-history',
        ]))->assertOk()->assertSee('data-build-card', false)->getContent());
        File::put($directory.'/build.html', $this->renderPage(route('builds.show', $build))->assertOk()
            ->assertSee('Deployment evidence')
            ->assertSee('data-modal-trigger="build-note-dialog"', false)
            ->assertSee('data-modal-trigger="build-comparison-dialog"', false)
            ->assertSee('data-modal-trigger="build-website-health-checks-dialog"', false)
            ->getContent());
        File::put($directory.'/build-note-dialog.html', $this->renderPage(route('builds.show', [
            'build' => $build,
            'dialog' => 'operator-note',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/build-comparison-dialog.html', $this->renderPage(route('builds.show', [
            'build' => $build,
            'dialog' => 'compare-build-'.$build->id.'-'.$previousBuild->id,
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/build-website-health-checks-dialog.html', $this->renderPage(route('builds.show', [
            'build' => $build,
            'dialog' => 'build-website-health-checks-dialog',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/build-comparison-content.html', $this->renderPage(route('builds.compare', [
            'build' => $build,
            'baseline' => $previousBuild,
            'fragment' => 'build-comparison',
        ]))->assertOk()
            ->assertSee('data-build-comparison-content', false)
            ->assertSee('Fixture baseline failure')->getContent());
        File::put($directory.'/backups.html', $this->renderPage(route('backups.index'))->assertOk()
            ->assertSee('Protection status')
            ->assertSee('data-modal-trigger="backup-schedule-dialog"', false)
            ->assertSee('data-modal-trigger="backup-destination-create-dialog"', false)
            ->assertSee('data-modal-trigger="backup-destination-edit-'.$destination->id.'"', false)
            ->assertSee('id="backup-destination-edit-'.$destination->id.'"', false)
            ->assertSee('data-modal-content-loaded="false"', false)
            ->getContent());
        File::put($directory.'/backups-dialog.html', $this->renderPage(route('backups.index', ['dialog' => 'add-schedule']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/backups-destination-dialog.html', $this->renderPage(route('backups.index', ['dialog' => 'add-destination']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/backups-destination-edit-dialog.html', $this->renderPage(route('backups.index', ['dialog' => 'edit-destination-'.$destination->id]))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/backup-destination-edit-content.html', $this->renderPage(route('backups.destinations.edit', [
            'destination' => $destination,
            'fragment' => 1,
            'return_to' => route('backups.index'),
        ]))->assertOk()->assertSee('<form', false)->getContent());
        File::put($directory.'/domains.html', $this->renderPage(route('domains.index'))->assertOk()
            ->assertSee('Add domain')->getContent());
        File::put($directory.'/domains-dialog.html', $this->renderPage(route('domains.index', ['dialog' => 'add-domain']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        app(IncidentNotifier::class)->fail($owner, 'server', $server->id, 'Fixture server incident', 'Fixture incident summary.');
        $statusPage = $owner->currentOrganization->statusPages()->create([
            'created_by' => $owner->id,
            'name' => 'Fixture status page',
            'slug' => 'fixture-status',
            'is_published' => true,
        ]);
        $statusPage->websites()->attach($website);
        $statusPage->incidents()->create([
            'created_by' => $owner->id,
            'kind' => 'incident',
            'status' => 'investigating',
            'severity' => 'major',
            'title' => 'Fixture incident update',
            'message' => 'Fixture incident message.',
            'starts_at' => now(),
        ]);
        File::put($directory.'/observability.html', $this->renderPage(route('observability.index'))->assertOk()
            ->assertSee('Start with what needs attention')
            ->assertSee('data-modal-trigger="metric-rule-dialog"', false)
            ->assertSee('data-modal-trigger="alert-destination-create-dialog"', false)
            ->assertSee('data-modal-trigger="status-page-create-dialog"', false)
            ->assertSee('data-modal-trigger="status-incident-create-dialog"', false)
            ->assertSee('data-modal-trigger="operational-incident-note-', false)
            ->assertSee('data-modal-trigger="status-incident-edit-dialog-', false)
            ->getContent());
        File::put($directory.'/observability-environment-context.html', $this->renderPage(route('observability.environments.context', $environment))->assertOk()
            ->assertSee('data-modal-trigger="environment-health-checks-dialog"', false)
            ->getContent());
        File::put($directory.'/observability-environment-context-dialog.html', $this->renderPage(route('observability.environments.context', [
            'environment' => $environment,
            'dialog' => 'environment-health-checks-dialog',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/observability-metric-rule-dialog.html', $this->renderPage(route('observability.index', ['dialog' => 'create-metric-rule']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/observability-destination-dialog.html', $this->renderPage(route('observability.index', ['dialog' => 'create-alert-destination']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/observability-status-page-dialog.html', $this->renderPage(route('observability.index', ['dialog' => 'create-status-page']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/observability-status-incident-dialog.html', $this->renderPage(route('observability.index', ['dialog' => 'create-status-incident']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/observability-status-incident-edit-dialog.html', $this->renderPage(route('observability.index', ['dialog' => 'edit-status-incident-'.$statusPage->incidents()->sole()->id]))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
    }

    /** Render a fresh request with Livewire's per-request asset state reset. */
    private function renderPage(string $url): TestResponse
    {
        Livewire::flushState();

        return $this->get($url);
    }
}
