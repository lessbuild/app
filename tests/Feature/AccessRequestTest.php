<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\User;
use App\Notifications\AccessInvitationNotification;
use App\Notifications\AccessRequestReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lessbuild.registration.enabled' => false,
            'lessbuild.registration.allow_first_user' => false,
        ]);
    }

    public function test_closed_registration_has_a_complete_public_request_path(): void
    {
        $this->get('/')->assertOk()->assertSee(route('access-request.create'))->assertSee('Request access');
        $this->get(route('pricing'))->assertOk()->assertSee('Request access')->assertDontSee('Start 14-day trial');
        $this->get(route('login'))->assertOk()->assertSee('Request an account');
        $this->get(route('access-request.create', ['plan' => 'pro']))
            ->assertOk()->assertSee('Request access')->assertSee('value="pro" selected', false);
    }

    public function test_open_registration_sends_visitors_to_account_creation(): void
    {
        config(['lessbuild.registration.enabled' => true]);

        $this->get(route('access-request.create'))->assertRedirect(route('register'));
        $this->post(route('access-request.store'), [])->assertRedirect(route('register'));
        $this->get(route('pricing'))->assertSee('Start 14-day trial')->assertSee(route('register'));
    }

    public function test_request_is_validated_normalized_and_encrypted_at_rest(): void
    {
        Notification::fake();
        $response = $this->post(route('access-request.store'), [
            'name' => ' Ada Lovelace ',
            'email' => 'ADA@Example.COM ',
            'company' => 'Analytical Engines',
            'team_size' => '2-5',
            'plan' => 'pro',
            'use_case' => 'We operate several Laravel applications on Hetzner.',
        ]);

        $response->assertRedirect(route('access-request.create'))->assertSessionHas('access_requested');
        $lead = AccessRequest::query()->sole();
        $this->assertSame('ada@example.com', $lead->email);
        $this->assertSame('Ada Lovelace', $lead->name);
        $this->assertSame(hash('sha256', 'ada@example.com'), $lead->email_hash);
        $raw = DB::table('access_requests')->first();
        $this->assertStringNotContainsString('ada@example.com', $raw->email);
        $this->assertStringNotContainsString('Ada Lovelace', $raw->name);
        $this->assertStringNotContainsString('Laravel applications', $raw->use_case);
        Notification::assertSentOnDemand(AccessRequestReceivedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ada@example.com');
    }

    public function test_duplicate_pending_request_updates_instead_of_disclosing_membership(): void
    {
        $payload = ['name' => 'Ada', 'email' => 'ada@example.com', 'use_case' => str_repeat('Initial deployment. ', 2)];
        $this->post(route('access-request.store'), $payload)->assertSessionHas('access_requested');
        $this->post(route('access-request.store'), [...$payload, 'use_case' => str_repeat('Updated deployment. ', 2)])
            ->assertSessionHas('access_requested');

        $this->assertSame(1, AccessRequest::query()->count());
        $this->assertStringContainsString('Updated', AccessRequest::query()->sole()->use_case);

        AccessRequest::query()->sole()->update(['status' => 'declined']);
        $this->post(route('access-request.store'), [...$payload, 'use_case' => str_repeat('Overwrite attempt. ', 2)])
            ->assertSessionHas('access_requested');
        $this->assertSame('declined', AccessRequest::query()->sole()->status);
        $this->assertStringNotContainsString('Overwrite', AccessRequest::query()->sole()->use_case);
    }

    public function test_honeypot_is_a_successful_no_op(): void
    {
        $this->post(route('access-request.store'), ['website' => 'https://spam.test'])
            ->assertSessionHas('access_requested');
        $this->assertDatabaseCount('access_requests', 0);
    }

    public function test_admin_can_review_requests_and_ordinary_users_cannot(): void
    {
        Notification::fake();
        config(['lessbuild.platform_admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $user = User::factory()->create();
        $lead = AccessRequest::query()->create([
            'email_hash' => hash('sha256', 'lead@example.com'), 'email' => 'lead@example.com',
            'name' => '<script>alert(1)</script>', 'use_case' => 'A sufficiently detailed deployment use case.',
        ]);

        $this->actingAs($user)->get(route('admin.access-requests.index'))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.access-requests.update', $lead), ['status' => 'contacted'])->assertForbidden();
        $this->actingAs($admin)->get(route('admin.access-requests.index'))
            ->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($admin)->patch(route('admin.access-requests.update', $lead), [
            'status' => 'invited', 'review_notes' => 'Invite sent manually.',
        ])->assertSessionHas('success');

        $lead->refresh();
        $this->assertSame('invited', $lead->status);
        $this->assertSame($admin->id, $lead->reviewed_by);
        $this->assertNotNull($lead->reviewed_at);
        $this->assertNotNull($lead->invitation_token_hash);
        Notification::assertSentOnDemand(AccessInvitationNotification::class);
    }

    public function test_invited_applicant_can_register_once_while_public_registration_is_closed(): void
    {
        Notification::fake();
        config(['lessbuild.platform_admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $lead = AccessRequest::query()->create([
            'email_hash' => hash('sha256', 'lead@example.com'), 'email' => 'lead@example.com',
            'name' => 'Invited Lead', 'use_case' => 'A sufficiently detailed deployment use case.',
        ]);
        $this->actingAs($admin)->patch(route('admin.access-requests.update', $lead), ['status' => 'invited']);
        Notification::assertSentOnDemand(AccessInvitationNotification::class, function ($notification) use (&$invitationUrl): bool {
            $invitationUrl = $notification->url;
            return true;
        });
        $this->post(route('logout'));

        $this->followingRedirects()->get($invitationUrl)->assertOk()->assertSee('lead@example.com')->assertSee('readonly', false);
        parse_str((string) parse_url($invitationUrl, PHP_URL_QUERY), $query);
        $payload = [
            'invite' => $query['invite'], 'name' => 'Invited Lead', 'email' => 'lead@example.com',
            'password' => 'A-strong-password-2026!', 'password_confirmation' => 'A-strong-password-2026!',
        ];
        $this->post(route('register'), $payload)->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('users', ['email' => 'lead@example.com']);
        $lead->refresh();
        $this->assertNotNull($lead->accepted_at);
        $this->assertNull($lead->invitation_token_hash);
        $this->assertSame('accepted', $lead->status);
        $this->post(route('logout'));
        $this->get($invitationUrl)->assertRedirect(route('login'));
    }

    public function test_invitation_cannot_be_used_with_another_email_or_after_expiry(): void
    {
        $lead = AccessRequest::query()->create([
            'email_hash' => hash('sha256', 'lead@example.com'), 'email' => 'lead@example.com',
            'name' => 'Invited Lead', 'use_case' => 'A sufficiently detailed deployment use case.',
            'status' => 'invited', 'invitation_token_hash' => hash('sha256', str_repeat('a', 64)),
            'invitation_expires_at' => now()->addHour(),
        ]);
        $payload = ['invite' => str_repeat('a', 64), 'name' => 'Attacker', 'email' => 'other@example.com', 'password' => 'A-strong-password-2026!', 'password_confirmation' => 'A-strong-password-2026!'];
        $this->post(route('register'), $payload)->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'other@example.com']);

        $lead->update(['invitation_expires_at' => now()->subSecond()]);
        $this->get(route('register', ['invite' => str_repeat('a', 64)]))->assertRedirect(route('login'));
    }

    public function test_invitation_is_not_rotated_unless_admin_explicitly_resends_it(): void
    {
        Notification::fake();
        config(['lessbuild.platform_admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $lead = AccessRequest::query()->create([
            'email_hash' => hash('sha256', 'lead@example.com'), 'email' => 'lead@example.com', 'name' => 'Lead',
            'use_case' => 'A sufficiently detailed deployment use case.', 'status' => 'pending',
        ]);
        $this->actingAs($admin)->patch(route('admin.access-requests.update', $lead), ['status' => 'invited']);
        $originalHash = $lead->fresh()->invitation_token_hash;
        Notification::fake();

        $this->patch(route('admin.access-requests.update', $lead), ['status' => 'invited', 'review_notes' => 'Still waiting.']);
        $this->assertSame($originalHash, $lead->fresh()->invitation_token_hash);
        Notification::assertNothingSent();

        $this->patch(route('admin.access-requests.update', $lead), ['status' => 'invited', 'resend_invitation' => '1']);
        $this->assertNotSame($originalHash, $lead->fresh()->invitation_token_hash);
        Notification::assertSentOnDemand(AccessInvitationNotification::class);
    }

    public function test_invalid_request_returns_safe_validation_errors(): void
    {
        $this->from(route('access-request.create'))->post(route('access-request.store'), [
            'name' => '', 'email' => 'not-an-email', 'team_size' => '1000', 'plan' => 'enterprise-plus', 'use_case' => 'short',
        ])->assertRedirect(route('access-request.create'))->assertSessionHasErrors(['name', 'email', 'team_size', 'plan', 'use_case']);
        $this->assertDatabaseCount('access_requests', 0);
    }

    public function test_admin_export_is_filtered_private_and_spreadsheet_safe(): void
    {
        config(['lessbuild.platform_admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $user = User::factory()->create();
        AccessRequest::query()->create([
            'email_hash' => hash('sha256', 'lead@example.com'), 'email' => 'lead@example.com',
            'name' => '=IMPORTXML("bad")', 'use_case' => "Operate production\nservers safely.", 'status' => 'pending',
        ]);
        AccessRequest::query()->create([
            'email_hash' => hash('sha256', 'declined@example.com'), 'email' => 'declined@example.com',
            'name' => 'Declined', 'use_case' => 'A sufficiently detailed deployment use case.', 'status' => 'declined',
        ]);

        $this->actingAs($user)->get(route('admin.access-requests.export'))->assertForbidden();
        $response = $this->actingAs($admin)->get(route('admin.access-requests.export', ['status' => 'pending']))->assertOk();
        $this->assertStringContainsString("'=IMPORTXML", $response->streamedContent());
        $this->assertStringContainsString('lead@example.com', $response->streamedContent());
        $this->assertStringNotContainsString('declined@example.com', $response->streamedContent());
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_pruning_removes_only_old_completed_requests(): void
    {
        foreach ([
            ['pending@example.com', 'pending', null],
            ['declined@example.com', 'declined', null],
            ['accepted@example.com', 'invited', now()->subDays(400)],
            ['recent@example.com', 'declined', null],
        ] as $index => [$email, $status, $accepted]) {
            $lead = AccessRequest::query()->create([
                'email_hash' => hash('sha256', $email), 'email' => $email, 'name' => 'Lead',
                'use_case' => 'A sufficiently detailed deployment use case.', 'status' => $status, 'accepted_at' => $accepted,
            ]);
            if ($index < 3) $lead->update(['updated_at' => now()->subDays(400)]);
        }

        $this->artisan('buildpusher:access-requests:prune', ['--days' => 365])->assertSuccessful();
        $this->assertDatabaseHas('access_requests', ['email_hash' => hash('sha256', 'pending@example.com')]);
        $this->assertDatabaseMissing('access_requests', ['email_hash' => hash('sha256', 'declined@example.com')]);
        $this->assertDatabaseMissing('access_requests', ['email_hash' => hash('sha256', 'accepted@example.com')]);
        $this->assertDatabaseHas('access_requests', ['email_hash' => hash('sha256', 'recent@example.com')]);
        $this->artisan('buildpusher:access-requests:prune', ['--days' => 10])->assertFailed();
    }
}
