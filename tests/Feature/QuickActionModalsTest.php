<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QuickActionModalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $account = Account::factory()->withMember($this->owner)->create();
        $this->owner->forceFill(['current_account_id' => $account->id])->save();
    }

    public function test_quick_actions_open_in_modals_with_the_pages_as_fallbacks(): void
    {
        $dashboard = $this->actingAs($this->owner)->get('/dashboard')->assertOk()
            ->assertSee('data-modal-trigger="new-project"', false)->assertSee('href="'.route('projects.create').'"', false);
        $this->assertModal($dashboard, 'new-project', open: false);

        $this->assertModal($this->actingAs($this->owner)->get('/account/members?dialog=invite-member')->assertOk(), 'invite-member', open: true);
        $this->assertModal($this->actingAs($this->owner)->get('/account/members')->assertOk(), 'invite-member', open: false);
        $this->assertModal($this->actingAs($this->owner)->get('/account/api-tokens?dialog=create-api-token')->assertOk(), 'create-api-token', open: true);
        $this->actingAs($this->owner)->get('/projects/create')->assertOk();
    }

    public function test_a_modal_reopens_with_its_errors_when_its_form_fails(): void
    {
        $page = $this->actingAs($this->owner)->from('/dashboard')->followingRedirects()->post('/projects', ['_modal' => 'new-project', 'name' => ''])->assertOk()->assertSee('aria-invalid="true"', false);
        $this->assertModal($page, 'new-project', open: true);
        $this->assertModal($page, 'feedback-modal', open: false);

        $this->actingAs($this->owner)->from('/dashboard')->post('/projects', ['_modal' => 'new-project', 'name' => 'Shop'])->assertRedirect();
        $this->assertSame('Shop', $this->owner->currentAccount?->projects()->value('name'));
    }

    /**
     * Assert a modal is on the page, and whether it opens as the page loads.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @param  string  $id
     * @param  bool  $open
     * @return void
     */
    private function assertModal(TestResponse $response, string $id, bool $open): void
    {
        $this->assertMatchesRegularExpression('/<dialog\s+id="'.preg_quote($id, '/').'"\s+data-modal-sheet\s+data-modal-initial-open="'.($open ? 'true' : 'false').'"/', (string) $response->getContent());
    }
}
