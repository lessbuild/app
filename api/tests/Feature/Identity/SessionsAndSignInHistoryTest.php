<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Actions\Users\SignOutBrowsers;
use App\Data\Users\SocialProfile;
use App\Enums\SignInMethod;
use App\Enums\SocialProvider;
use App\Events\Users\BrowsersSignedOut;
use App\Models\SignInEvent;
use App\Models\SocialIdentity;
use App\Models\User;
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

    /**
     * Password sign-ins, and failures on known accounts, are recorded.
     */
    public function test_password_sign_ins_and_failures_on_known_accounts_are_recorded(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-123']);

        $this->withHeader('User-Agent', self::FIREFOX)->postJson('/api/app/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->postJson('/api/app/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
        $this->withHeader('User-Agent', self::FIREFOX)->postJson('/api/app/auth/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertOk();

        $events = SignInEvent::query()->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame([false, true], $events->pluck('succeeded')->all());
        $success = $events->last();
        $this->assertNotNull($success);
        $this->assertSame(SignInMethod::Password, $success->method);
        $this->assertSame('127.0.0.1', $success->ip_address);
        $this->assertSame(2, SignInEvent::query()->count());
    }

    /**
     * Two-factor sign-ins record the first factor and the challenge.
     */
    public function test_two_factor_sign_ins_record_the_first_factor_and_the_challenge(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-123']);
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETSECRETSECR'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->postJson('/api/app/auth/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertOk()->assertJsonPath('two_factor', true);
        $this->postJson('/api/app/auth/two-factor-challenge', ['code' => '000000']);
        $this->postJson('/api/app/auth/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertNoContent();

        $events = SignInEvent::query()->orderBy('id')->get();
        $this->assertSame([false, true], $events->pluck('succeeded')->all());
        $this->assertTrue($events->every(fn (SignInEvent $event): bool => $event->two_factor && $event->method === SignInMethod::Password));
    }

    /**
     * Provider sign-ins record the provider, even through the two-factor challenge.
     */
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
        $this->postJson('/api/app/auth/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertNoContent();

        $event = SignInEvent::query()->sole();
        $this->assertSame(SignInMethod::GitLab, $event->method);
        $this->assertTrue($event->two_factor);
    }

    /**
     * Signing up is recorded as the first sign-in.
     */
    public function test_registration_is_recorded_as_the_first_sign_in(): void
    {
        $this->postJson('/api/app/auth/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery']);

        $this->assertSame(SignInMethod::Registration, SignInEvent::query()->sole()->method);
    }

    /**
     * The sessions page lists the person's browsers and sign-ins, and signs other browsers out.
     */
    public function test_the_sessions_page_lists_browsers_and_history_and_signs_other_browsers_out(): void
    {
        Event::fake([BrowsersSignedOut::class]);
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['remember_token' => 'original-token']);
        $this->insertSession('other-browser', $user, self::FIREFOX);
        $this->insertSession('third-browser', $user, null);
        $this->insertSession('someone-elses', User::factory()->create(), null);
        (new SignInEvent)->forceFill(['user_id' => $user->id, 'succeeded' => false, 'method' => SignInMethod::Password, 'user_agent' => self::FIREFOX, 'ip_address' => '203.0.113.9', 'created_at' => now()])->save();

        $page = $this->actingAs($user)->getJson('/api/app/settings/sessions')->assertOk()->assertSee('Firefox on macOS')->assertJsonPath('signIns.0.ipAddress', '203.0.113.9');
        $this->assertContains('other-browser', array_column((array) $page->json('sessions'), 'id'));
        $this->assertNotContains('someone-elses', array_column((array) $page->json('sessions'), 'id'));

        $this->actingAs($user)->deleteJson('/api/app/settings/sessions/someone-elses')->assertJsonPath('message', __('That browser was already signed out.'));
        $this->actingAs($user)->deleteJson('/api/app/settings/sessions/other-browser')->assertOk()->assertJsonPath('redirect', '/settings/sessions')->assertJsonPath('message', __('That browser is signed out.'));
        $this->assertSame(['someone-elses', 'third-browser'], $this->seededSessionsLeft());
        $this->assertNotSame('original-token', $user->refresh()->getRememberToken());

        $this->actingAs($user)->deleteJson('/api/app/settings/sessions')->assertJsonPath('message', __('All other browsers are signed out.'));
        $this->assertSame(['someone-elses'], $this->seededSessionsLeft());
        Event::assertDispatchedTimes(BrowsersSignedOut::class, 2);
    }

    /**
     * The current browser is never signed out.
     */
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

    /**
     * Without a session store that can list sessions, there's no list (and the page says why).
     */
    public function test_the_page_explains_when_sessions_cannot_be_listed(): void
    {
        config(['session.driver' => 'array']);

        $this->actingAs(User::factory()->create())->getJson('/api/app/settings/sessions')->assertOk()->assertJsonPath('sessions', null);
    }

    /**
     * Sign-in history is pruned after the retention period.
     */
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
