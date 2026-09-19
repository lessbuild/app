<?php

use App\Models\Build;
use App\Models\Recipe;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
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
        File::ensureDirectoryExists($directory);

        foreach (['landing' => '/', 'login' => '/login', 'pricing' => '/pricing'] as $name => $url) {
            File::put($directory.'/'.$name.'.html', $this->renderPage($url)->assertOk()->getContent());
        }

        $owner = User::factory()->create(['name' => 'Layout fixture owner']);
        $this->actingAs($owner);
        File::put($directory.'/dashboard.html', $this->renderPage(route('dashboard'))->assertOk()->getContent());
        File::put($directory.'/organization.html', $this->renderPage(route('organizations.index'))->assertOk()->getContent());
        File::put($directory.'/organization-dialog.html', $this->renderPage(route('organizations.index', ['dialog' => 'invite-member']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/feedback.html', $this->renderPage(route('feedback.index'))->assertOk()
            ->assertSee('Send private feedback')->getContent());
        File::put($directory.'/feedback-dialog.html', $this->renderPage(route('feedback.index', ['dialog' => 'compose-feedback']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/notifications.html', $this->renderPage(route('notifications.index'))->assertOk()
            ->assertSee('Save current')->getContent());
        File::put($directory.'/notifications-dialog.html', $this->renderPage(route('notifications.index', ['dialog' => 'save-filter']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
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
        $entitlementEnforcement = config('billing.enforce_entitlements');
        config(['billing.enforce_entitlements' => true]);
        File::put($directory.'/provider-create.html', $this->renderPage(route('providers.create'))->assertOk()->getContent());
        config(['billing.enforce_entitlements' => $entitlementEnforcement]);

        $project = $owner->currentOrganization->projects()->create([
            'name' => 'A deliberately long layout fixture application name', 'slug' => 'layout-fixture', 'created_by' => $owner->id,
        ]);
        $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
        ]);
        File::put($directory.'/automation.html', $this->renderPage(route('automation.index'))->assertOk()
            ->assertSee('Automate routine release work')
            ->assertSee('data-modal-trigger="automation-token-dialog"', false)
            ->assertSee('data-modal-trigger="automation-schedule-dialog-', false)
            ->assertSee('data-modal-trigger="automation-task-dialog-', false)
            ->getContent());
        File::put($directory.'/automation-dialog.html', $this->renderPage(route('automation.index', ['dialog' => 'create-token']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/projects.html', $this->renderPage(route('projects.index'))->assertOk()
            ->assertSee('A deliberately long layout fixture application name')->getContent());
        $provider = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => 'github', 'token' => 'fixture-token', 'description' => 'Test',
        ]);
        $server = $owner->servers()->create(['name' => 'Server', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test',
            'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $owner->currentOrganization->backupDestinations()->create([
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
        $project->environments()->where('type', 'production')->firstOrFail()->update([
            'server_id' => $server->id,
            'website_id' => $website->id,
        ]);
        File::put($directory.'/project-detail.html', $this->renderPage(route('projects.show', $project))->assertOk()
            ->assertSee('data-modal-trigger="add-environment-dialog"', false)
            ->assertSee('data-modal-trigger="environment-variable-dialog-', false)
            ->assertSee('data-modal-trigger="environment-process-dialog-', false)
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

        $build = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'setup_stage' => 15,
            'revision' => str_repeat('a', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
        File::put($directory.'/build.html', $this->renderPage(route('builds.show', $build))->assertOk()
            ->assertSee('Deployment evidence')
            ->assertSee('data-modal-trigger="build-note-dialog"', false)
            ->getContent());
        File::put($directory.'/build-note-dialog.html', $this->renderPage(route('builds.show', [
            'build' => $build,
            'dialog' => 'operator-note',
        ]))->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/backups.html', $this->renderPage(route('backups.index'))->assertOk()
            ->assertSee('Protection status')
            ->assertSee('data-modal-trigger="backup-schedule-dialog"', false)
            ->getContent());
        File::put($directory.'/backups-dialog.html', $this->renderPage(route('backups.index', ['dialog' => 'add-schedule']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/domains.html', $this->renderPage(route('domains.index'))->assertOk()
            ->assertSee('Add domain')->getContent());
        File::put($directory.'/domains-dialog.html', $this->renderPage(route('domains.index', ['dialog' => 'add-domain']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
        File::put($directory.'/observability.html', $this->renderPage(route('observability.index'))->assertOk()
            ->assertSee('Start with what needs attention')
            ->assertSee('data-modal-trigger="metric-rule-dialog"', false)
            ->getContent());
        File::put($directory.'/observability-metric-rule-dialog.html', $this->renderPage(route('observability.index', ['dialog' => 'create-metric-rule']))
            ->assertOk()->assertSee('data-modal-initial-open="true"', false)->getContent());
    }

    /** Render a fresh request with Livewire's per-request asset state reset. */
    private function renderPage(string $url): TestResponse
    {
        Livewire::flushState();

        return $this->get($url);
    }
}
