<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_users_can_correct_their_account_but_cannot_manage_infrastructure(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
        $this->get(route('servers.index'))
            ->assertRedirect(route('verification.notice'));
        $this->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Verify your email')
            ->assertSee(route('verification.send'));
        $this->get(route('verification.notice'))
            ->assertSuccessful()
            ->assertSee($user->email)
            ->assertSee('Correct my email');
    }

    public function test_verification_email_can_be_resent_and_verified_users_skip_the_prompt(): void
    {
        Notification::fake();
        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($unverified, VerifyEmail::class);

        $verified = User::factory()->create();
        $this->actingAs($verified)->get(route('verification.notice'))
            ->assertRedirect(route('dashboard'));
        $this->post(route('verification.send'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_signed_verification_link_marks_the_email_verified(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Email address verified.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));
        $this->get(route('dashboard'))->assertSuccessful();
    }

    public function test_signed_link_rejects_the_wrong_email_hash(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('another@example.com'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_changing_email_requires_reverification_but_keeps_account_settings_available(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => $user->name,
            'email' => 'replacement@example.com',
        ])->assertSessionHas('profile_status');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('replacement@example.com');
    }
}
