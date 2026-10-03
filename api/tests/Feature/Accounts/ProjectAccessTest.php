<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ProjectAccessTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that a member limited to some projects can't open or find the others, and that owners can't be limited.
     *
     * @return void
     */
    public function test_members_can_be_limited_to_some_projects(): void
    {
        $shop = Project::factory()->withServices(['deploy'])->create(['name' => 'Shop']);
        $owner = $this->ownerOf($shop);
        $blog = Project::factory()->for($shop->account)->withServices(['deploy'])->create(['name' => 'Blog']);
        $member = User::factory()->create();
        $membership = $this->addMember($shop, $member, AccountRole::Member);
        $member->forceFill(['current_account_id' => $shop->account_id])->save();

        $this->actingAs($owner)->get('/account/members')->assertOk()->assertSee('Project access');
        $this->actingAs($owner)->put("/account/members/{$membership->id}/projects", ['project_access' => 'some', 'projects' => [$shop->id]])->assertRedirect('/account/members');
        $this->assertSame([$shop->id], $membership->refresh()->project_ids);

        $this->actingAs($member)->get("/projects/{$shop->id}")->assertOk();
        $this->actingAs($member)->get("/projects/{$blog->id}")->assertNotFound();
        $this->actingAs($member)->get('/dashboard')->assertOk()->assertSee('Shop')->assertDontSee('Blog');
        $this->actingAs($member)->getJson('/search?q=bl')->assertOk()->assertJsonMissing(['title' => 'Blog']);
        $this->actingAs($owner)->get("/projects/{$blog->id}")->assertOk();
        $this->actingAs($owner)->getJson('/search?q=bl')->assertOk()->assertJsonFragment(['title' => 'Blog']);

        $ownerMembership = Membership::query()->where('user_id', $owner->id)->sole();
        $this->actingAs($owner)->put("/account/members/{$ownerMembership->id}/projects", ['project_access' => 'some', 'projects' => []])->assertSessionHasErrors();
        $this->actingAs($owner)->put("/account/members/{$membership->id}/projects", ['project_access' => 'all'])->assertRedirect();
        $this->actingAs($member)->get("/projects/{$blog->id}")->assertOk();
    }

    /**
     * Check that a protected environment takes deploys and changes only from owners, admins and members allowed to
     * deploy protected environments.
     *
     * @return void
     */
    public function test_protected_environments_need_permission_to_deploy(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $member = User::factory()->create();
        $membership = $this->addMember($project, $member, AccountRole::Member);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id])->id]);
        $repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id])->id, 'environment_id' => $production->id]);
        $deploy = "/projects/{$project->id}/deploy/repositories/{$repository->id}/builds";
        $controls = "/projects/{$project->id}/deploy/environments/{$production->id}/controls";

        $this->actingAs($owner)->put($controls, ['protected' => '1'])->assertRedirect();
        $this->assertTrue($production->refresh()->protected);

        $this->actingAs($member)->post($deploy)->assertForbidden();
        $this->actingAs($member)->put($controls, ['protected' => '0'])->assertForbidden();
        $this->assertSame(0, Build::query()->count());

        $membership->forceFill(['deploy_protected' => true])->save();
        $this->actingAs($member)->post($deploy)->assertRedirect();
        $this->assertSame(1, Build::query()->count());

        $staging = $project->environments()->where('slug', '!=', 'production')->first();
        if ($staging !== null) {
            $membership->forceFill(['deploy_protected' => false])->save();
            $this->assertTrue($member->can('configureDeploy', $staging));
        }
    }
}
