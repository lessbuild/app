<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Account;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SavedViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Olive Owner']);
        $this->account = Account::factory()->withMember($this->owner)->create();
        $this->owner->forceFill(['current_account_id' => $this->account->id])->save();
    }

    /**
     * Saved views are private, limited to the pages that allow them, and reopen their filters.
     */
    public function test_saved_views_are_private_limited_to_the_page_and_reopen_its_filters(): void
    {
        $this->actingAs($this->owner)->postJson('/api/app/saved-views', ['saved_view_page' => 'audit-log', 'saved_view_name' => 'Security', 'query' => ['category' => 'security', 'evil' => 'x']])
            ->assertCreated()->assertJsonPath('url', route('account.audit-log', ['category' => 'security']));
        $view = SavedView::query()->sole();
        $this->assertSame(['category' => 'security'], $view->query);
        $this->actingAs($this->owner)->getJson('/api/app/saved-views?page=audit-log')->assertJsonPath('views.0.name', 'Security')->assertJsonPath('views.0.url', route('account.audit-log', ['category' => 'security']));

        // The same name replaces the view; pages that aren't allowed are refused.
        $this->actingAs($this->owner)->postJson('/api/app/saved-views', ['saved_view_page' => 'audit-log', 'saved_view_name' => 'Security', 'query' => ['category' => 'billing']])->assertCreated();
        $this->assertSame(['category' => 'billing'], $view->refresh()->query);
        $this->actingAs($this->owner)->postJson('/api/app/saved-views', ['saved_view_page' => 'admin', 'saved_view_name' => 'x'])->assertJsonValidationErrors('saved_view_page');
        $this->actingAs($this->owner)->postJson('/api/app/saved-views', ['saved_view_page' => 'monitoring.events', 'saved_view_name' => 'x', 'query' => ['level' => 'error']])->assertJsonValidationErrors('saved_view_name');

        $stranger = User::factory()->create();
        Account::factory()->withMember($stranger)->create();
        $this->actingAs($stranger)->getJson('/api/app/saved-views?page=audit-log')->assertJsonPath('views', []);
        $this->actingAs($stranger)->deleteJson("/api/app/saved-views/{$view->id}")->assertNotFound();
        $this->actingAs($this->owner)->deleteJson("/api/app/saved-views/{$view->id}")->assertOk();
        $this->assertSame(0, SavedView::query()->count());
    }
}
