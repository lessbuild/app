<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Repository;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use App\Services\Infrastructure\WebsiteProvisioner;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class EnvironmentRecipesTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Environment $production;

    private Server $server;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->link(Website::factory()->create(['server_id' => $this->server->id, 'deployment_slug' => 'shop']));
        $this->base = "/projects/{$this->project->id}/deploy/environments/{$this->production->id}";
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_recipes_are_ordered_snapshots_that_run_on_the_environments_servers(): void
    {
        [$firewall, $tools] = [$this->recipe('Firewall', 'ufw allow 22'), $this->recipe('Tools', 'apt-get install -y htop')];
        $this->as()->post("{$this->base}/recipes", ['recipe_id' => $tools->id])->assertRedirect("{$this->base}?tab=recipes");
        $this->as()->post("{$this->base}/recipes", ['recipe_id' => $firewall->id])->assertRedirect();
        $this->as()->post("{$this->base}/recipes", ['recipe_id' => $firewall->id])->assertSessionHasErrors('recipe_id');

        $second = $this->production->recipes()->where('recipe_id', $firewall->id)->sole();
        $this->as()->post("{$this->base}/recipes/{$second->id}/move", ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['Firewall', 'Tools'], $this->production->recipes()->pluck('name')->all());

        $this->as()->post("{$this->base}/recipes/run")->assertRedirect()->assertSessionHas('status', 'Queued on 1 server.');
        $execution = ServerCommandExecution::query()->sole();
        $this->assertSame([$this->server->id, $this->owner->id], [$execution->server_id, $execution->user_id]);
        $this->assertLessThan(strpos($execution->command, base64_encode('apt-get install -y htop')), strpos($execution->command, base64_encode('ufw allow 22')));
        $this->assertStringContainsString('== Recipe 1/2: Firewall', $execution->command);

        // A library edit shows up as a change to take; the snapshot keeps the old script until then.
        $this->travel(1)->minutes();
        $this->as()->put("/account/recipes/{$firewall->id}", ['name' => 'Firewall', 'category' => 'security', 'script' => 'ufw allow 22 && ufw enable'])->assertRedirect();
        $entry = $this->production->recipes()->where('recipe_id', $firewall->id)->sole();
        $this->assertTrue($entry->isBehind());
        $this->as()->get("{$this->base}?tab=recipes")->assertOk()->assertSee('Library has changes');
        $this->as()->post("{$this->base}/recipes/{$entry->id}/refresh")->assertRedirect();
        $this->assertSame(['ufw allow 22 && ufw enable', false], [$entry->refresh()->script, $entry->isBehind()]);

        $this->as()->delete("{$this->base}/recipes/{$entry->id}")->assertRedirect("{$this->base}?tab=recipes");
        $this->assertSame(['Tools'], $this->production->recipes()->pluck('name')->all());
        $this->assertTrue(Recipe::query()->whereKey($firewall->id)->exists());
    }

    public function test_only_the_accounts_recipes_can_be_added_and_viewers_cant_run_them(): void
    {
        $other = Project::factory()->withServices(['infrastructure'])->create();
        $theirs = new Recipe;
        $theirs->forceFill(['account_id' => $other->account_id, 'name' => 'Theirs', 'script' => 'echo hi'])->save();
        $this->as()->post("{$this->base}/recipes", ['recipe_id' => $theirs->id])->assertSessionHasErrors('recipe_id');

        $this->as()->post("{$this->base}/recipes", ['recipe_id' => $this->recipe('Tools', 'echo tools')->id])->assertRedirect();
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->post("{$this->base}/recipes/run")->assertForbidden();
        $this->assertSame(0, ServerCommandExecution::query()->count());
    }

    public function test_recipes_run_by_themselves_on_a_new_website_when_asked(): void
    {
        $this->as()->post("{$this->base}/recipes", ['recipe_id' => $this->recipe('Tools', 'echo tools')->id])->assertRedirect();
        $this->as()->put("{$this->base}/recipes/settings", ['run_on_new_websites' => '1'])->assertRedirect();
        $this->assertTrue($this->production->refresh()->recipes_run_on_new_websites);

        $website = Website::factory()->provisioning(Website::STATUS_PROVISIONING, 0)->create(['server_id' => $this->server->id]);
        $this->link($website);
        foreach (range(1, WebsiteProvisioner::finalStage()) as $stage) {
            $this->post(ProvisioningCallbackUrl::websiteStatus($website), ['status' => $stage])->assertNoContent();
        }

        $execution = ServerCommandExecution::query()->sole();
        $this->assertSame([$this->server->id, null], [$execution->server_id, $execution->user_id]);
        $this->assertStringContainsString(base64_encode('echo tools'), $execution->command);
    }

    /**
     * Connect a repository that deploys the production environment to a website.
     *
     * @param  Website  $website
     * @return void
     */
    private function link(Website $website): void
    {
        $github = Provider::query()->where('account_id', $this->project->account_id)->where('type', ProviderType::GitHub)->first()
            ?? Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id]);
        Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $website->id, 'provider_id' => $github->id, 'environment_id' => $this->production->id]);
    }

    /**
     * Save a recipe in the account's library.
     *
     * @param  string  $name
     * @param  string  $script
     * @return Recipe
     */
    private function recipe(string $name, string $script): Recipe
    {
        $this->as()->post('/account/recipes', ['name' => $name, 'category' => 'utilities', 'script' => $script])->assertRedirect();

        return Recipe::query()->where('name', $name)->sole();
    }

    /**
     * Act as the account's owner.
     *
     * @return $this
     */
    private function as(): static
    {
        return $this->actingAs($this->owner);
    }
}
