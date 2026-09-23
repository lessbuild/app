<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Models\User;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_unverified_accounts_are_sent_to_email_verification_before_dashboard_access(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('analytics.verification.notice'));

        $this->actingAs($user)->get(route('analytics.verification.notice'))
            ->assertOk()
            ->assertSee('Verify your email address');
    }

    public function test_unverified_accounts_can_sign_out_from_the_verification_screen(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $this->post(route('analytics.logout'))
            ->assertRedirect(route('analytics.home'));

        $this->assertGuest();
    }
}
