<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\SavedView;
use App\Models\User;
use App\Notifications\NewFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ActivityFiltersTest extends TestCase
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

    public function test_saved_views_are_private_limited_to_the_page_and_reopen_its_filters(): void
    {
        $this->actingAs($this->owner)->get('/account/audit-log?category=security')->assertOk()->assertSee('data-modal-trigger="save-view-audit-log"', false);

        $this->actingAs($this->owner)->post('/saved-views', ['saved_view_page' => 'audit-log', 'saved_view_name' => 'Security', 'query' => ['category' => 'security', 'evil' => 'x']])
            ->assertRedirect(route('account.audit-log', ['category' => 'security']));
        $view = SavedView::query()->sole();
        $this->assertSame(['category' => 'security'], $view->query);
        $this->actingAs($this->owner)->get('/account/audit-log')->assertSee('Security')->assertSee(route('account.audit-log', ['category' => 'security']));

        // The same name replaces the view; pages that aren't allowed are refused.
        $this->actingAs($this->owner)->post('/saved-views', ['saved_view_page' => 'audit-log', 'saved_view_name' => 'Security', 'query' => ['category' => 'billing']])->assertRedirect();
        $this->assertSame(['category' => 'billing'], $view->refresh()->query);
        $this->actingAs($this->owner)->post('/saved-views', ['saved_view_page' => 'admin', 'saved_view_name' => 'x'])->assertSessionHasErrors('saved_view_page');
        $this->actingAs($this->owner)->post('/saved-views', ['saved_view_page' => 'monitoring.events', 'saved_view_name' => 'x', 'query' => ['level' => 'error']])->assertSessionHasErrors('saved_view_name');

        $stranger = User::factory()->create();
        Account::factory()->withMember($stranger)->create();
        $this->actingAs($stranger)->get('/account/audit-log')->assertDontSee(route('account.audit-log', ['category' => 'billing']));
        $this->actingAs($stranger)->delete("/saved-views/{$view->id}")->assertNotFound();
        $this->actingAs($this->owner)->delete("/saved-views/{$view->id}")->assertRedirect();
        $this->assertSame(0, SavedView::query()->count());
    }

    /**
     * Record an audit entry in the account.
     *
     * @param  User  $actor
     * @param  AuditAction  $action
     * @param  array<string, mixed>  $context
     * @param  \Illuminate\Support\Carbon  $at
     * @return void
     */
    private function entry(User $actor, AuditAction $action, array $context, \Illuminate\Support\Carbon $at): void
    {
        $entry = new AuditEntry;
        $entry->forceFill(['account_id' => $this->account->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'actor_email' => $actor->email, 'action' => $action, 'context' => $context, 'created_at' => $at])->save();
    }

    /**
     * Put a notification in the owner's inbox.
     *
     * @param  string  $title
     * @param  string  $body
     * @param  bool  $read
     * @param  string  $type
     * @return void
     */
    private function notify(string $title, string $body, bool $read, string $type = 'App\Notifications\BuildFinished'): void
    {
        DatabaseNotification::query()->forceCreate([
            'id' => (string) Str::uuid(), 'type' => $type, 'notifiable_type' => $this->owner->getMorphClass(), 'notifiable_id' => $this->owner->id,
            'data' => ['title' => $title, 'body' => $body, 'url' => '/dashboard', 'account_id' => $this->account->id], 'read_at' => $read ? now() : null,
        ]);
    }
}
