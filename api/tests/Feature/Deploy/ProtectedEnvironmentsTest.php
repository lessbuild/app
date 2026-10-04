<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Build;
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

final class ProtectedEnvironmentsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

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
        $deploy = "/api/app/projects/{$project->id}/deploy/repositories/{$repository->id}/builds";
        $controls = "/api/app/projects/{$project->id}/deploy/environments/{$production->id}/controls";

        $this->actingAs($owner)->putJson($controls, ['protected' => '1'])->assertSuccessful();
        $this->assertTrue($production->refresh()->protected);

        $this->actingAs($member)->postJson($deploy)->assertForbidden();
        $this->actingAs($member)->putJson($controls, ['protected' => '0'])->assertForbidden();
        $this->assertSame(0, Build::query()->count());

        $membership->forceFill(['deploy_protected' => true])->save();
        $this->actingAs($member)->postJson($deploy)->assertSuccessful();
        $this->assertSame(1, Build::query()->count());

        $staging = $project->environments()->where('slug', '!=', 'production')->first();
        if ($staging !== null) {
            $membership->forceFill(['deploy_protected' => false])->save();
            $this->assertTrue($member->can('configureDeploy', $staging));
        }
    }
}
