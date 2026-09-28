<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\PlatformAdminEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_see_why_email_wont_arrive_without_secrets(): void
    {
        config(['mail.default' => 'log', 'mail.from.address' => 'hello@example.com', 'mail.mailers.smtp.password' => 'hunter2']);
        $this->actingAs(User::factory()->create())->get('/admin/email')->assertNotFound();

        $this->as($this->admin())->get('/admin/email')->assertOk()
            ->assertSee('The log mailer doesn’t send anything', false)->assertSee('MAIL_FROM_ADDRESS')->assertDontSee('hunter2');

        config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@buildpusher.com', 'mail.mailers.smtp.host' => 'smtp.example.net', 'mail.mailers.smtp.username' => 'apikey']);
        $this->as($this->admin())->get('/admin/email')->assertOk()->assertSee('Email is set up to be delivered')->assertSee('smtp.example.net')->assertDontSee('hunter2')->assertDontSee('apikey');
    }

    public function test_a_test_email_is_sent_now_and_its_outcome_recorded(): void
    {
        config(['mail.default' => 'array']);
        $admin = $this->admin();

        $this->as($admin)->post('/admin/email/test', ['to' => 'not-an-email'])->assertSessionHasErrors('to');
        $this->as($admin)->post('/admin/email/test', ['to' => 'ops@example.net'])->assertRedirect('/admin/email')->assertSessionHas('status');

        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $sent = $transport->messages()->sole();
        $this->assertSame('ops@example.net', $sent->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame('email.test', PlatformAdminEvent::query()->sole()->action);
        $this->as($admin)->get('/admin/email')->assertSee('Last test')->assertSee('ops@example.net');

        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $this->as($admin)->post('/admin/email/test', ['to' => 'ops@example.net'])->assertRedirect()->assertSessionHas('error');
        $this->as($admin)->get('/admin/email')->assertSee('failed:', false);
    }

    /**
     * Make a platform admin with a second factor.
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
