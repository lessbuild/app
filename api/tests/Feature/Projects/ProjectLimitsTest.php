<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\EnvironmentResource;
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

final class ProjectLimitsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $shop;

    private Project $secret;

    private User $member;

    private Server $server;

    private Website $secretSite;

    private Provider $github;

    /**
     * Two projects in one account. A Member who can see only the first, and a website and server that belong to the
     * second (the website serves the second project's environment, on a shared server).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $this->shop = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->secret = Project::factory()->withServices(['deploy', 'infrastructure'])->create(['account_id' => $this->shop->account_id]);
        $this->onTier($this->shop, 'deploy', 'pro');
        $this->member = User::factory()->create();
        $this->addMember($this->shop, $this->member, AccountRole::Member)->forceFill(['project_ids' => [$this->shop->id]])->save();
        $this->server = Server::factory()->create(['account_id' => $this->shop->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $this->shop->account_id])->id, 'mysql_root_password' => 'x']);
        $this->secretSite = Website::factory()->create(['account_id' => $this->shop->account_id, 'server_id' => $this->server->id, 'environment_id' => $this->secret->environments()->firstOrFail()->id, 'deployment_slug' => 'secret', 'url' => 'secret.example.com']);
        $this->github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->shop->account_id]);
    }

    /**
     * A project-limited member can't connect a repository to, deploy over, read the database of, or see a website
     * that belongs to a project they're not in — even though it's in the same account and on a shared server.
     *
     * @return void
     */
    public function test_a_limited_member_cannot_reach_another_projects_website(): void
    {
        $shopEnv = $this->shop->environments()->firstOrFail()->id;

        // Connect a repository to the other project's website: refused.
        $this->actingAs($this->member)->postJson("/api/app/projects/{$this->shop->id}/deploy/repositories", [
            'name' => 'evil', 'provider_id' => $this->github->id, 'url' => 'github.com/evil/app', 'branch' => 'main', 'website_id' => $this->secretSite->id, 'environment_id' => $shopEnv,
        ])->assertJsonValidationErrors('website_id');
        $this->assertSame(0, Repository::query()->where('website_id', $this->secretSite->id)->count());

        // Point a managed MySQL resource at the other project's database: refused.
        $this->actingAs($this->member)->postJson("/api/app/projects/{$this->shop->id}/deploy/environments/{$shopEnv}/resources", [
            'name' => 'db', 'type' => 'mysql', 'is_managed' => '1', 'database_website_id' => $this->secretSite->id,
        ])->assertJsonValidationErrors('database_website_id');
        $this->assertSame(0, EnvironmentResource::query()->count());

        // The other project's website and server pages, and the website in lists, are hidden.
        $this->actingAs($this->member)->getJson("/api/app/projects/{$this->shop->id}/infrastructure/websites/{$this->secretSite->id}")->assertForbidden();
        $this->actingAs($this->member)->getJson("/api/app/projects/{$this->shop->id}/infrastructure/servers/{$this->server->id}")->assertForbidden();
        $this->actingAs($this->member)->getJson("/api/app/projects/{$this->shop->id}/infrastructure/websites")->assertOk()->assertJsonMissing(['url' => 'secret.example.com']);

        $this->assertSame(0, Build::query()->count());
    }

    /**
     * The same member can use a website and server that are in their own project.
     *
     * @return void
     */
    public function test_a_limited_member_can_use_their_own_projects_resources(): void
    {
        $shopEnv = $this->shop->environments()->firstOrFail()->id;
        $ownSite = Website::factory()->create(['account_id' => $this->shop->account_id, 'server_id' => $this->server->id, 'environment_id' => $shopEnv, 'deployment_slug' => 'shop', 'url' => 'shop.example.com']);

        $this->actingAs($this->member)->postJson("/api/app/projects/{$this->shop->id}/deploy/repositories", [
            'name' => 'shop', 'provider_id' => $this->github->id, 'url' => 'github.com/shop/app', 'branch' => 'main', 'website_id' => $ownSite->id, 'environment_id' => $shopEnv,
        ])->assertSuccessful();
        $this->assertSame(1, Repository::query()->where('website_id', $ownSite->id)->count());
        // The shared server is now reachable, because one of the member's websites runs on it.
        $this->actingAs($this->member)->getJson("/api/app/projects/{$this->shop->id}/infrastructure/websites/{$ownSite->id}")->assertOk();
        $this->actingAs($this->member)->getJson("/api/app/projects/{$this->shop->id}/infrastructure/servers/{$this->server->id}")->assertOk();
    }

    /**
     * An unlimited member (project_ids null) keeps account-wide access to every project's website.
     *
     * @return void
     */
    public function test_an_unlimited_member_keeps_account_wide_access(): void
    {
        $this->addMember($this->shop, $all = User::factory()->create(), AccountRole::Member);
        $this->actingAs($all)->getJson("/api/app/projects/{$this->shop->id}/infrastructure/websites/{$this->secretSite->id}")->assertOk();
    }
}
