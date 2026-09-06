<?php

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

        foreach (['landing' => '/', 'login' => '/login'] as $name => $url) {
            File::put($directory.'/'.$name.'.html', $this->renderPage($url)->assertOk()->getContent());
        }

        $owner = User::factory()->create(['name' => 'Layout fixture owner']);
        $this->actingAs($owner);
        File::put($directory.'/dashboard.html', $this->renderPage(route('dashboard'))->assertOk()->getContent());

        $project = $owner->currentOrganization->projects()->create([
            'name' => 'Layout fixture application', 'slug' => 'layout-fixture', 'created_by' => $owner->id,
        ]);
        $provider = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => 'github', 'token' => 'fixture-token', 'description' => 'Test',
        ]);
        $server = $owner->servers()->create(['name' => 'Server', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test',
            'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App',
            'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test',
        ]);
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
    }

    /** Render a fresh request with Livewire's per-request asset state reset. */
    private function renderPage(string $url): TestResponse
    {
        Livewire::flushState();

        return $this->get($url);
    }
}
