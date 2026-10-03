<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Enums\AccountRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
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

        $this->actingAs($owner)->getJson('/api/app/account/members')->assertOk()->assertJsonPath('projects.0.name', 'Blog');
        $this->actingAs($owner)->putJson("/api/app/account/members/{$membership->id}/projects", ['project_access' => 'some', 'projects' => [$shop->id]])->assertOk()->assertJsonPath('redirect', '/account/members');
        $this->assertSame([$shop->id], $membership->refresh()->project_ids);

        $this->actingAs($member)->getJson("/api/app/projects/{$shop->id}")->assertOk();
        $this->actingAs($member)->getJson("/api/app/projects/{$blog->id}")->assertNotFound();
        $this->assertSame(['Shop'], array_column((array) $this->actingAs($member)->getJson('/api/app/dashboard')->assertOk()->json('projects'), 'name'));
        $this->actingAs($owner)->getJson("/api/app/projects/{$blog->id}")->assertOk();

        $ownerMembership = Membership::query()->where('user_id', $owner->id)->sole();
        $this->actingAs($owner)->putJson("/api/app/account/members/{$ownerMembership->id}/projects", ['project_access' => 'some', 'projects' => []])->assertUnprocessable();
        $this->actingAs($owner)->putJson("/api/app/account/members/{$membership->id}/projects", ['project_access' => 'all'])->assertOk();
        $this->actingAs($member)->getJson("/api/app/projects/{$blog->id}")->assertOk();
    }
}
