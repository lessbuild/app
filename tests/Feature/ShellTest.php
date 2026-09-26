<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_switcher_lists_your_accounts_and_switches_between_them(): void
    {
        $user = User::factory()->create();
        $acme = Account::factory()->withMember($user)->create(['name' => 'Acme']);
        $globex = Account::factory()->withMember($user)->create(['name' => 'Globex']);
        $stranger = Account::factory()->withMember(User::factory()->create())->create(['name' => 'Initech']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('accounts.switch', $globex->id), false)
            ->assertDontSee('Initech');

        $this->actingAs($user)->post("/accounts/{$globex->id}/switch")->assertRedirect('/dashboard');
        $this->assertSame($globex->id, $user->refresh()->current_account_id);
        $this->actingAs($user)->post("/accounts/{$stranger->id}/switch")->assertNotFound();
        $this->assertNotSame($acme->id, $user->refresh()->current_account_id);
    }

    public function test_the_sidebar_shows_the_project_sections_and_the_account_sections_you_can_use(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create(['name' => 'Acme']);
        $project = Project::factory()->for($account)->withServices(['deploy', 'analytics'])->create(['name' => 'Storefront']);
        Project::factory()->for($account)->create(['name' => 'Blog']);

        $this->actingAs($owner)->get("/projects/{$project->id}/services/deploy")
            ->assertOk()
            ->assertSee(route('projects.services.show', [$project->id, 'analytics']), false)
            ->assertDontSee(route('projects.services.show', [$project->id, 'monitoring']), false)
            ->assertSee(route('projects.show', Project::query()->where('name', 'Blog')->sole()->id), false)
            ->assertSee(route('account.audit-log'), false)
            ->assertSee(route('projects.settings', $project), false);

        $member = User::factory()->create();
        $account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member, 'service_access' => ['analytics']]);
        $member->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($member)->get("/projects/{$project->id}")
            ->assertOk()
            ->assertSee(route('projects.services.show', [$project->id, 'analytics']), false)
            ->assertDontSee('href="'.route('projects.services.show', [$project->id, 'deploy']).'"', false)
            ->assertDontSee(route('account.audit-log'), false)
            ->assertDontSee(route('account.api-tokens'), false);
    }
}
