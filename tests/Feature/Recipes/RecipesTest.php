<?php

declare(strict_types=1);

namespace Tests\Feature\Recipes;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Models\Server;
use App\Models\User;
use App\Notifications\RecipeReported;
use App\Notifications\RecipeReportResolved;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class RecipesTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Project $otherProject;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->otherProject = Project::factory()->withServices(['infrastructure'])->create();
        $this->otherOwner = $this->ownerOf($this->otherProject);
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_an_account_keeps_recipes_with_their_history(): void
    {
        $this->actingAs($this->owner)->post('/account/recipes', $this->recipe(['script' => "apt-get install -y htop\r\necho done\r\n"]))->assertRedirect();
        $recipe = Recipe::query()->sole();
        $this->assertSame("apt-get install -y htop\necho done", $recipe->script);
        $this->assertSame(['created'], $recipe->revisions()->pluck('change')->all());

        $this->actingAs($this->owner)->put("/account/recipes/{$recipe->id}", $this->recipe(['script' => 'apt-get install -y btop']))->assertRedirect();
        $this->actingAs($this->owner)->put("/account/recipes/{$recipe->id}", $this->recipe(['script' => 'apt-get install -y btop']))->assertRedirect();
        $this->assertSame(['edited', 'created'], $recipe->revisions()->pluck('change')->all());
        $this->actingAs($this->owner)->get("/account/recipes/{$recipe->id}")->assertOk()->assertSee('apt-get install -y btop')->assertSee('Edited');

        $viewer = $this->member(AccountRole::Viewer);
        $this->actingAs($viewer)->get('/account/recipes')->assertOk()->assertSee('Tools')->assertDontSee('New recipe');
        $this->actingAs($viewer)->put("/account/recipes/{$recipe->id}", $this->recipe())->assertForbidden();
        $this->actingAs($this->otherOwner)->get("/account/recipes/{$recipe->id}")->assertNotFound();

        $this->actingAs($this->owner)->post("/account/recipes/{$recipe->id}/duplicate")->assertRedirect();
        $this->assertSame(['Copy of Tools', 'Tools'], Recipe::query()->orderBy('name')->pluck('name')->all());
        $this->actingAs($this->owner)->delete("/account/recipes/{$recipe->id}")->assertRedirect('/account/recipes');
        $this->assertSame(1, Recipe::query()->count());
    }

    public function test_servers_run_the_chosen_recipes_in_order_and_keep_that_version(): void
    {
        $this->onTier($this->project, 'deploy', 'pro');
        $first = $this->saved(['name' => 'Firewall', 'script' => 'ufw allow 22']);
        $second = $this->saved(['name' => 'Tools', 'script' => 'apt-get install -y htop']);
        $foreign = $this->saved(['name' => 'Theirs', 'script' => 'echo theirs'], $this->otherProject->account);
        $provider = Provider::factory()->create(['account_id' => $this->project->account_id]);
        $server = ['provider_id' => $provider->id, 'type' => 'app', 'name' => 'web-1', 'region' => 'fra1', 'size' => 's-1vcpu-1gb', 'image' => 'ubuntu-24-04-x64'];
        $url = "/projects/{$this->project->id}/infrastructure/servers";

        $this->actingAs($this->owner)->get("{$url}/create")->assertOk()->assertSee('Firewall')->assertDontSee('Theirs');
        $this->actingAs($this->owner)->post($url, [...$server, 'recipe_ids' => [$foreign->id]])->assertSessionHasErrors('recipe_ids');
        $this->actingAs($this->owner)->post($url, [...$server, 'recipe_ids' => [$second->id, $first->id]])->assertRedirect();

        $created = Server::query()->sole();
        $this->assertSame(['Tools', 'Firewall'], array_column($created->provisioningRecipes(), 'name'));
        $userData = (string) $this->cloud->created[0]['user_data'];
        $this->assertLessThan(strpos($userData, 'ufw allow 22'), strpos($userData, 'apt-get install -y htop'));

        $this->actingAs($this->owner)->put("/account/recipes/{$second->id}", $this->recipe(['name' => 'Tools', 'script' => 'apt-get install -y btop']))->assertRedirect();
        $this->assertSame('apt-get install -y htop', $created->refresh()->provisioningRecipes()[0]['script']);
    }

    public function test_published_recipes_install_once_per_account_and_copies_follow_new_revisions(): void
    {
        $recipe = $this->saved();
        $member = $this->member(AccountRole::Member);
        $this->actingAs($member)->put("/account/recipes/{$recipe->id}/publication", ['published' => '1'])->assertForbidden();
        $this->actingAs($this->otherOwner)->get("/recipes/gallery/{$recipe->id}")->assertNotFound();

        $this->actingAs($this->owner)->put("/account/recipes/{$recipe->id}/publication", ['published' => '1'])->assertRedirect();
        $this->actingAs($this->otherOwner)->get('/recipes/gallery')->assertOk()->assertSee('Tools')->assertSee('0 installs');
        $this->actingAs($this->otherOwner)->get("/recipes/gallery/{$recipe->id}")->assertOk()->assertSee('apt-get install -y htop')->assertSee('Install into');

        $this->actingAs($this->otherOwner)->post("/recipes/gallery/{$recipe->id}/install")->assertRedirect();
        $copy = Recipe::query()->where('account_id', $this->otherProject->account_id)->sole();
        $this->actingAs($this->otherOwner)->post("/recipes/gallery/{$recipe->id}/install")->assertRedirect("/account/recipes/{$copy->id}");
        $this->assertSame([1, false, $recipe->id, 'installed'], [$recipe->refresh()->install_count, $copy->is_published, $copy->source_recipe_id, $copy->revisions()->value('change')]);
        $this->assertFalse($copy->hasGalleryUpdate());

        // The publisher changes the script: the copy sees an update, previews it, and takes it.
        $this->travel(1)->minutes();
        $this->actingAs($this->owner)->put("/account/recipes/{$recipe->id}", $this->recipe(['script' => 'apt-get install -y btop']))->assertRedirect();
        $this->assertTrue($copy->refresh()->hasGalleryUpdate());
        $this->actingAs($this->otherOwner)->get('/account/recipes')->assertSee('Update available');
        $this->actingAs($this->otherOwner)->get("/account/recipes/{$copy->id}")->assertSee('The gallery has a newer version')->assertSee('apt-get install -y btop');
        $this->actingAs($this->otherOwner)->post("/account/recipes/{$copy->id}/refresh")->assertRedirect();
        $this->assertSame(['apt-get install -y btop', 'refreshed'], [$copy->refresh()->script, $copy->revisions()->value('change')]);
        $this->assertFalse($copy->hasGalleryUpdate());

        // Unpublishing hides it from the gallery; copies stay but can't refresh.
        $this->actingAs($this->owner)->put("/account/recipes/{$recipe->id}/publication", ['published' => '0'])->assertRedirect();
        $this->actingAs($this->otherOwner)->get("/recipes/gallery/{$recipe->id}")->assertNotFound();
        $this->actingAs($this->otherOwner)->post("/account/recipes/{$copy->id}/refresh")->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame(2, Recipe::query()->count());
    }

    public function test_the_gallery_searches_sorts_and_keeps_favourites_and_ratings(): void
    {
        $tools = $this->saved(['name' => 'Tools', 'category' => 'utilities'], published: true);
        $redis = $this->saved(['name' => 'Redis tuning', 'category' => 'database', 'description' => 'Sensible memory limits'], published: true);
        $this->saved(['name' => 'Private', 'category' => 'database']);

        $this->actingAs($this->otherOwner)->get('/recipes/gallery?q=memory')->assertOk()->assertSee('Redis tuning')->assertDontSee('Tools')->assertDontSee('Private');
        $this->actingAs($this->otherOwner)->get('/recipes/gallery?category=utilities')->assertSee('Tools')->assertDontSee('Redis tuning');

        $this->actingAs($this->otherOwner)->put("/recipes/gallery/{$redis->id}/favorite")->assertRedirect();
        $this->actingAs($this->otherOwner)->get('/recipes/gallery?favorites=1')->assertSee('Redis tuning')->assertDontSee('>Tools<', false);

        // Only people whose account installed it, outside the publisher, rate it.
        $this->actingAs($this->otherOwner)->put("/recipes/gallery/{$redis->id}/rating", ['rating' => 5])->assertForbidden();
        $this->actingAs($this->otherOwner)->post("/recipes/gallery/{$redis->id}/install")->assertRedirect();
        $this->actingAs($this->otherOwner)->put("/recipes/gallery/{$redis->id}/rating", ['rating' => 9])->assertSessionHasErrors('rating');
        $this->actingAs($this->otherOwner)->put("/recipes/gallery/{$redis->id}/rating", ['rating' => 4])->assertRedirect();
        $this->actingAs($this->owner)->put("/recipes/gallery/{$tools->id}/rating", ['rating' => 5])->assertForbidden();
        $this->actingAs($this->otherOwner)->get('/recipes/gallery?sort=rating')->assertSeeInOrder(['Redis tuning', 'Tools'])->assertSee('4.0★ from 1');
        $this->actingAs($this->otherOwner)->get('/recipes/gallery')->assertSeeInOrder(['Redis tuning', 'Tools']);

        $this->actingAs($this->otherOwner)->delete("/recipes/gallery/{$redis->id}/rating")->assertRedirect();
        $this->actingAs($this->otherOwner)->delete("/recipes/gallery/{$redis->id}/favorite")->assertRedirect();
        $this->assertSame([0, 0], [$redis->ratings()->count(), $redis->favorites()->count()]);
    }

    public function test_reports_reach_the_publisher_and_their_resolution_reaches_the_reporter(): void
    {
        Notification::fake();
        $recipe = $this->saved(published: true);
        $this->actingAs($this->owner)->put("/recipes/gallery/{$recipe->id}/report", ['reason' => 'spam'])->assertForbidden();

        $this->actingAs($this->otherOwner)->put("/recipes/gallery/{$recipe->id}/report", ['reason' => 'malicious', 'details' => 'It opens port 23.'])->assertRedirect();
        Notification::assertSentTo($this->owner, RecipeReported::class);
        $report = RecipeReport::query()->sole();
        $this->actingAs($this->owner)->get('/account/recipes/reports')->assertOk()->assertSee('It opens port 23.')->assertSee('Harmful or malicious');
        $this->actingAs($this->owner)->get("/account/recipes/{$recipe->id}")->assertSee('1 open report');

        $this->actingAs($this->otherOwner)->put("/account/recipes/reports/{$report->id}", ['resolved' => '1'])->assertNotFound();
        $this->actingAs($this->owner)->put("/account/recipes/reports/{$report->id}", ['resolved' => '1', 'resolution_note' => 'Removed the telnet line.'])->assertRedirect();
        Notification::assertSentTo($this->otherOwner, RecipeReportResolved::class);
        $this->actingAs($this->otherOwner)->get('/recipes/gallery/reports')->assertOk()->assertSee('Resolved')->assertSee('Removed the telnet line.');

        // Changing the report opens it again; withdrawing removes it.
        $this->actingAs($this->otherOwner)->put("/recipes/gallery/{$recipe->id}/report", ['reason' => 'broken'])->assertRedirect();
        $this->assertSame(['open', null], [$report->refresh()->status, $report->resolution_note]);
        $this->actingAs($this->otherOwner)->delete("/recipes/gallery/{$recipe->id}/report")->assertRedirect();
        $this->assertSame(0, RecipeReport::query()->count());

        $member = $this->member(AccountRole::Member);
        $this->actingAs($member)->get('/account/recipes/reports')->assertForbidden();
    }

    /**
     * Add someone to the main account with a role, working in it.
     *
     * @param  AccountRole  $role
     * @return User
     */
    private function member(AccountRole $role): User
    {
        $user = User::factory()->create();
        $this->addMember($this->project, $user, $role);
        $user->forceFill(['current_account_id' => $this->project->account_id])->save();

        return $user;
    }

    /**
     * Get recipe form fields, with overrides.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function recipe(array $overrides = []): array
    {
        return ['name' => 'Tools', 'category' => 'utilities', 'description' => 'Handy tools', 'script' => 'apt-get install -y htop', ...$overrides];
    }

    /**
     * Save a recipe through the page as the account's owner, publishing it if asked.
     *
     * @param  array<string, string>  $overrides
     * @param  Account|null  $account  another account than the main project's
     * @param  bool  $published
     * @return Recipe
     */
    private function saved(array $overrides = [], ?Account $account = null, bool $published = false): Recipe
    {
        $owner = $account === null ? $this->owner : $this->otherOwner;
        $this->actingAs($owner)->post('/account/recipes', $this->recipe($overrides))->assertRedirect();
        $recipe = Recipe::query()->latest('id')->firstOrFail();
        if ($published) {
            $this->actingAs($owner)->put("/account/recipes/{$recipe->id}/publication", ['published' => '1'])->assertRedirect();
        }

        return $recipe->refresh();
    }
}
