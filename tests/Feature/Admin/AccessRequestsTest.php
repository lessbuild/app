<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AccountRole;
use App\Models\AccessRequest;
use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\PlatformAdminEvent;
use App\Models\User;
use App\Notifications\AccessInvitation;
use App\Notifications\AccessRequestReceived;
use App\Notifications\NewAccessRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AccessRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple 42';

    public function test_open_registration_needs_no_request(): void
    {
        $this->get('/request-access')->assertRedirect('/register');
        $this->post('/register', $this->signUp('ada@example.com'))->assertRedirect();
        $this->assertTrue(User::query()->where('email', 'ada@example.com')->exists());
    }

    public function test_closed_registration_takes_requests_and_admits_invited_people_once(): void
    {
        Notification::fake();
        config(['platform.registration.open' => false]);
        $admin = $this->admin();

        $this->get('/register')->assertOk()->assertSee('Sign-up is by invitation');
        $this->post('/register', $this->signUp('ada@example.com'))->assertSessionHasErrors('email');
        $this->assertFalse(User::query()->where('email', 'ada@example.com')->exists());

        $request = ['name' => 'Ada', 'email' => 'Ada@Example.com', 'company' => 'Analytical', 'team_size' => '2-5', 'use_case' => 'Deploy the engine.'];
        $this->post('/request-access', $request)->assertRedirect('/request-access')->assertSessionHas('status');
        $this->post('/request-access', [...$request, 'use_case' => 'Deploy the engine, and monitor it.'])->assertRedirect();
        $record = AccessRequest::query()->sole();
        $this->assertSame(['ada@example.com', 'Deploy the engine, and monitor it.', 'pending'], [$record->email, $record->use_case, $record->status]);
        $this->assertNotSame('ada@example.com', AccessRequest::query()->toBase()->value('email'));
        Notification::assertSentOnDemandTimes(AccessRequestReceived::class, 1);
        Notification::assertSentTo($admin, NewAccessRequest::class);

        $this->as($admin)->get('/admin/access-requests')->assertOk()->assertSee('Deploy the engine, and monitor it.')->assertSee('Waiting (1)');
        $this->as($admin)->put("/admin/access-requests/{$record->id}", ['status' => 'accepted'])->assertSessionHasErrors('status');
        $this->as($admin)->put("/admin/access-requests/{$record->id}", ['status' => 'invited', 'review_notes' => 'Good fit'])->assertRedirect();
        $url = '';
        Notification::assertSentOnDemand(AccessInvitation::class, function (AccessInvitation $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url): bool {
            $url = (string) $notification->toMail($notifiable)->actionUrl;

            return $notifiable->routes['mail'] === 'ada@example.com';
        });
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $invite = is_string($query['invite'] ?? null) ? $query['invite'] : '';
        $this->assertSame(64, strlen($invite));
        $this->assertSame('access_request.reviewed', PlatformAdminEvent::query()->latest('id')->value('action'));

        auth()->logout();
        $this->get("/register?invite={$invite}")->assertOk()->assertSee('value="ada@example.com"', false)->assertDontSee('Sign-up is by invitation');
        $this->post('/register', [...$this->signUp('ada@example.com'), 'invite' => $invite])->assertRedirect();
        $this->assertTrue(User::query()->where('email', 'ada@example.com')->exists());
        $record->refresh();
        $this->assertSame(['accepted', null], [$record->status, $record->invitation_token_hash]);
        $this->assertNotNull($record->accepted_at);

        auth()->logout();
        $this->flushSession();
        $this->post('/register', [...$this->signUp('eve@example.com'), 'invite' => $invite])->assertSessionHasErrors('email');
        $this->as($admin)->put("/admin/access-requests/{$record->id}", ['status' => 'declined'])->assertSessionHasErrors('status');
    }

    public function test_people_invited_to_an_account_can_sign_up_while_registration_is_closed(): void
    {
        config(['platform.registration.open' => false]);
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $invitation = new AccountInvitation;
        $invitation->forceFill(['account_id' => $account->id, 'invited_by_id' => $owner->id, 'email' => 'grace@example.com', 'role' => AccountRole::Member, 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDay()])->save();

        $this->post('/register', $this->signUp('grace@example.com'))->assertRedirect();
        $this->assertTrue(User::query()->where('email', 'grace@example.com')->exists());
    }

    public function test_old_closed_requests_are_deleted(): void
    {
        foreach (['declined' => 200, 'accepted' => 200, 'pending' => 400, 'invited' => 10] as $status => $days) {
            $request = new AccessRequest;
            $request->forceFill(['email_hash' => AccessRequest::hashEmail("{$status}@example.com"), 'email' => "{$status}@example.com", 'name' => $status, 'use_case' => 'x', 'status' => $status])->save();
            AccessRequest::query()->whereKey($request->id)->update(['updated_at' => now()->subDays($days)]);
        }
        Artisan::call('access-requests:prune');
        $this->assertSame(['invited', 'pending'], AccessRequest::query()->orderBy('status')->pluck('status')->all());
    }

    /**
     * Get sign-up form fields.
     *
     * @param  string  $email
     * @return array<string, string>
     */
    private function signUp(string $email): array
    {
        return ['name' => 'New Person', 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    /**
     * Make a platform admin with an authenticator app.
     *
     * @return User
     */
    private function admin(): User
    {
        $user = User::factory()->create();
        Account::factory()->withMember($user)->create();
        $user->forceFill(['is_platform_admin' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    /**
     * Act as the admin with a fresh confirmation.
     *
     * @param  User  $admin
     * @return $this
     */
    private function as(User $admin): static
    {
        return $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }
}
