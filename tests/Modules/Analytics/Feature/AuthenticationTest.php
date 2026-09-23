<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_accounts_are_sent_to_email_verification_before_dashboard_access(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Verify your email address');
    }
}
