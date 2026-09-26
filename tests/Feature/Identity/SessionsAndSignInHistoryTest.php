<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\SignOutBrowsers;
use App\Domain\Identity\Data\SocialProfile;
use App\Domain\Identity\Enums\SignInMethod;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Events\BrowsersSignedOut;
use App\Domain\Identity\Models\SignInEvent;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeSocialSignInGateway;
use Tests\TestCase;

final class SessionsAndSignInHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:131.0) Gecko/20100101 Firefox/131.0';

    public function test_password_sign_ins_and_failures_on_known_accounts_are_recorded(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-123']);

        $this->withHeader('User-Agent', self::FIREFOX)->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
        $this->withHeader('User-Agent', self::FIREFOX)->post('/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertRedirect('/dashboard');

        $events = SignInEvent::query()->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame([false, true], $events->pluck('succeeded')->all());
        $success = $events->last();
        $this->assertNotNull($success);
        $this->assertSame(SignInMethod::Password, $success->method);
        $this->assertSame('127.0.0.1', $success->ip_address);
        $this->assertSame(2, SignInEvent::query()->count());
    }

    public function test_two_factor_sign_ins_record_the_first_factor_and_the_challenge(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-123']);
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETSECRETSECR'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertRedirect('/two-factor-challenge');
        $this->post('/two-factor-challenge', ['code' => '000000']);
        $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertRedirect('/dashboard');

        $events = SignInEvent::query()->orderBy('id')->get();
        $this->assertSame([false, true], $events->pluck('succeeded')->all());
        $this->assertTrue($events->every(fn (SignInEvent $event): bool => $event->two_factor && $event->method === SignInMethod::Password));
    }

    public function test_provider_sign_ins_record_the_provider_even_through_the_two_factor_challenge(): void
    {
        $gateway = new FakeSocialSignInGateway;
        $this->app->instance(SocialSignInGateway::class, $gateway);
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETSECRETSECR'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $identity = new SocialIdentity;
        $identity->forceFill(['user_id' => $user->id, 'provider' => SocialProvider::GitLab, 'provider_user_id' => 'gl-1'])->save();
        $gateway->profile = new SocialProfile('gl-1', $user->email, 'Name');

        $this->get('/auth/gitlab/callback?code=x')->assertRedirect('/two-factor-challenge');
        $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertRedirect('/dashboard');

        $event = SignInEvent::query()->sole();
        $this->assertSame(SignInMethod::GitLab, $event->method);
        $this->assertTrue($event->two_factor);
    }

    public function test_registration_is_recorded_as_the_first_sign_in(): void
    {
        $this->post('/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery']);

        $this->assertSame(SignInMethod::Registration, SignInEvent::query()->sole()->method);
    }

    public function test_the_sessions_page_lists_browsers_and_history_and_signs_other_browsers_out(): void
    {
        Event::fake([BrowsersSignedOut::class]);
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['remember_token' => 'original-token']);
        $this->insertSession('other-browser', $user, self::FIREFOX);
        $this->insertSession('third-browser', $user, null);
        $this->insertSession('someone-elses', User::factory()->create(), null);
        (new SignInEvent)->forceFill(['user_id' => $user->id, 'succeeded' => false, 'method' => SignInMethod::Password, 'user_agent' => self::FIREFOX, 'ip_address' => '203.0.113.9', 'created_at' => now()])->save();

        $this->actingAs($user)->get('/settings/sessions')
            ->assertOk()
            ->assertSee('Firefox on macOS')
            ->assertSee('203.0.113.9')
            ->assertSee(route('settings.sessions.destroy', 'other-browser'), false)
            ->assertDontSee(route('settings.sessions.destroy', 'someone-elses'), false);

        $this->actingAs($user)->delete('/settings/sessions/someone-elses')->assertSessionHas('status', 'browser-not-found');
        $this->actingAs($user)->delete('/settings/sessions/other-browser')->assertRedirect('/settings/sessions')->assertSessionHas('status', 'browser-signed-out');
        $this->assertSame(['someone-elses', 'third-browser'], $this->seededSessionsLeft());
        $this->assertNotSame('original-token', $user->refresh()->getRememberToken());

        $this->actingAs($user)->delete('/settings/sessions')->assertSessionHas('status', 'other-browsers-signed-out');
        $this->assertSame(['someone-elses'], $this->seededSessionsLeft());
        Event::assertDispatchedTimes(BrowsersSignedOut::class, 2);
    }

    public function test_the_current_browser_is_never_signed_out(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $this->insertSession('current', $user, null);
        $this->insertSession('other', $user, null);
        $signOut = app(SignOutBrowsers::class);

        $this->assertSame(0, $signOut->handle($user, 'current', 'current'));
        $this->assertSame(1, $signOut->handle($user, 'current'));
        $this->assertSame(['current'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_the_page_explains_when_sessions_cannot_be_listed(): void
    {
        config(['session.driver' => 'array']);

        $this->actingAs(User::factory()->create())->get('/settings/sessions')->assertOk()->assertSee(__('Browser sessions can’t be listed on this server.'));
    }

    public function test_sign_in_history_is_pruned_after_the_retention_period(): void
    {
        $user = User::factory()->create();
        foreach ([SignInEvent::RETENTION_DAYS + 1, 1] as $daysAgo) {
            (new SignInEvent)->forceFill(['user_id' => $user->id, 'succeeded' => true, 'created_at' => now()->subDays($daysAgo)])->save();
        }

        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [SignInEvent::class]]));

        $this->assertSame(1, SignInEvent::query()->count());
    }

    /** @return list<string> */
    private function seededSessionsLeft(): array
    {
        return array_values(DB::table('sessions')->whereIn('id', ['other-browser', 'third-browser', 'someone-elses'])->orderBy('id')->pluck('id')->map(strval(...))->all());
    }

    private function insertSession(string $id, User $user, ?string $userAgent): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '198.51.100.4', 'user_agent' => $userAgent, 'payload' => '', 'last_activity' => now()->subMinutes(5)->getTimestamp()]);
    }
}
