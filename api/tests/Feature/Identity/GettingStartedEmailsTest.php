<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Onboarding\NextStep;
use App\Notifications\Onboarding\Welcome;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class GettingStartedEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_are_welcomed_when_they_confirm_their_address(): void
    {
        Notification::fake();
        [$keen, $quiet] = [User::factory()->create(), User::factory()->create(['getting_started_emails' => false])];

        event(new Verified($keen));
        event(new Verified($quiet));

        Notification::assertSentTo($keen, Welcome::class);
        $this->assertStringContainsString('email/getting-started/'.$keen->id.'/stop', implode(' ', (new Welcome)->toMail($keen)->outroLines));
        Notification::assertNotSentTo($quiet, Welcome::class);
    }

    public function test_one_reminder_names_the_next_step_to_people_who_stalled(): void
    {
        Notification::fake();
        $stalled = $this->person(daysAgo: 3);
        $project = Project::factory()->for(Account::query()->findOrFail($stalled->current_account_id))->create(['name' => 'Shop']);
        $noProject = $this->person(daysAgo: 4);
        $tooNew = $this->person(daysAgo: 1);
        $optedOut = $this->person(daysAgo: 3, emails: false);

        Artisan::call('onboarding:remind');
        Artisan::call('onboarding:remind');

        Notification::assertSentToTimes($stalled, NextStep::class, 1);
        Notification::assertSentTo($stalled, NextStep::class, fn (NextStep $mail): bool => str_contains((string) $mail->toMail($stalled)->subject, 'Shop') && in_array(route('projects.setup', $project), [$mail->toMail($stalled)->actionUrl], true));
        Notification::assertSentTo($noProject, NextStep::class, fn (NextStep $mail): bool => $mail->toMail($noProject)->subject === 'Your first project is a minute away');
        Notification::assertNothingSentTo($tooNew);
        Notification::assertNothingSentTo($optedOut);
    }

    public function test_the_signed_link_stops_them_after_a_confirmation_and_settings_turn_them_back_on(): void
    {
        $user = $this->person(daysAgo: 3);
        $link = URL::signedRoute('getting-started-emails.stop', ['user' => $user->id]);

        $this->get($link)->assertOk()->assertSee('Stop getting-started emails?');
        $this->assertTrue($user->refresh()->getting_started_emails);
        $this->post($link)->assertOk()->assertSee('You won’t get getting-started emails again.', false);
        $this->assertFalse($user->refresh()->getting_started_emails);
        $this->post(route('getting-started-emails.stop.store', ['user' => $user->id]))->assertForbidden();

        $this->actingAs($user)->put('/settings/notifications/getting-started', ['getting_started_emails' => '1'])->assertRedirect('/settings/notifications');
        $this->assertTrue($user->refresh()->getting_started_emails);
    }

    /**
     * Make a verified person with an account, who signed up some days ago.
     *
     * @param  int  $daysAgo
     * @param  bool  $emails
     * @return User
     */
    private function person(int $daysAgo, bool $emails = true): User
    {
        $user = User::factory()->create(['created_at' => now()->subDays($daysAgo), 'getting_started_emails' => $emails]);
        $account = Account::factory()->withMember($user)->create();
        $user->forceFill(['current_account_id' => $account->id])->save();

        return $user;
    }
}
