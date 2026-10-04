<?php

declare(strict_types=1);

namespace Tests\Feature\Feedback;

use App\Models\Account;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\NewFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * People send feedback from the app; admins are told, and only pages of ours are kept with it.
     */
    public function test_people_send_feedback_from_the_app_and_admins_are_told(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $user = User::factory()->create(['name' => 'Ada']);
        $account = Account::factory()->withMember($user)->create();
        $user->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($user)->postJson('/api/app/feedback', ['kind' => 'rant', 'message' => ''])->assertJsonValidationErrors(['kind', 'message']);
        $this->actingAs($user)->postJson('/api/app/feedback', ['kind' => 'idea', 'message' => 'Dark mode for status pages', 'page' => config('app.url').'/dashboard'])
            ->assertCreated()->assertJsonPath('message', __('Thanks — your feedback has been sent.'));

        $feedback = Feedback::query()->sole();
        $this->assertSame(['idea', 'Dark mode for status pages', $user->id, $account->id, config('app.url').'/dashboard'], [$feedback->kind, $feedback->message, $feedback->user_id, $feedback->account_id, $feedback->page]);
        $this->assertNotSame('Dark mode for status pages', DB::table('feedback')->value('message'));
        Notification::assertSentTo($admin, NewFeedback::class);
        Notification::assertNotSentTo($user, NewFeedback::class);

        // Pages from elsewhere aren't recorded.
        $this->actingAs($user)->postJson('/api/app/feedback', ['kind' => 'problem', 'message' => 'Hmm', 'page' => 'https://evil.example/x'])->assertCreated();
        $this->assertNull(Feedback::query()->latest('id')->first()?->page);
    }
}
