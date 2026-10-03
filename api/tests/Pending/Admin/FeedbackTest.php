<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Feedback\Pages\ManageFeedback;
use App\Models\Account;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\NewFeedback;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

final class FeedbackTest extends TestCase
{
    use AdminHelpers;
    use RefreshDatabase;

    public function test_people_send_feedback_from_the_app_and_admins_are_told(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Ada']);
        $account = Account::factory()->withMember($user)->create();
        $user->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('data-modal-trigger="feedback-modal"', false)->assertSee('id="feedback-modal"', false);
        $this->actingAs($user)->from('/dashboard')->post('/feedback', ['feedback_kind' => 'rant', 'feedback_message' => ''])->assertRedirect('/dashboard')->assertSessionHasErrors(['feedback_kind', 'feedback_message']);
        $this->actingAs($user)->from('/dashboard')->post('/feedback', ['feedback_kind' => 'idea', 'feedback_message' => 'Dark mode for status pages', 'feedback_page' => config('app.url').'/dashboard'])
            ->assertRedirect('/dashboard')->assertSessionHas('feedback');

        $feedback = Feedback::query()->sole();
        $this->assertSame(['idea', 'Dark mode for status pages', $user->id, $account->id, config('app.url').'/dashboard'], [$feedback->kind, $feedback->message, $feedback->user_id, $feedback->account_id, $feedback->page]);
        $this->assertNotSame('Dark mode for status pages', DB::table('feedback')->value('message'));
        Notification::assertSentTo($admin, NewFeedback::class);
        Notification::assertNotSentTo($user, NewFeedback::class);

        // Pages from elsewhere aren't recorded.
        $this->actingAs($user)->post('/feedback', ['feedback_kind' => 'problem', 'feedback_message' => 'Hmm', 'feedback_page' => 'https://evil.example/x'])->assertRedirect();
        $this->assertNull(Feedback::query()->latest('id')->first()?->page);
    }

    public function test_admins_read_and_resolve_feedback_and_nobody_else_can(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Grace']);
        $feedback = new Feedback;
        $feedback->forceFill(['user_id' => $user->id, 'kind' => 'problem', 'message' => 'The deploy log is cut off'])->save();

        $this->actingAs($user)->get('/admin/feedback')->assertNotFound();

        $this->as($admin)->get('/admin/feedback')->assertOk()->assertSee('The deploy log is cut off')->assertSee('Grace')->assertSee('Open');
        Livewire::test(ManageFeedback::class)->assertCanSeeTableRecords([$feedback])->callAction(TestAction::make('resolve')->table($feedback));
        $this->assertSame($admin->id, $feedback->refresh()->resolved_by);
        Livewire::test(ManageFeedback::class)->assertCanNotSeeTableRecords([$feedback]);
        Livewire::test(ManageFeedback::class, ['activeTab' => 'resolved'])->assertCanSeeTableRecords([$feedback])
            ->assertActionHasLabel(TestAction::make('resolve')->table($feedback), 'Open again')->callAction(TestAction::make('resolve')->table($feedback));
        $this->assertNull($feedback->refresh()->resolved_at);
    }
}
