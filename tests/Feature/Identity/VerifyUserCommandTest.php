<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VerifyUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_verify_an_email_address(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'someone@example.com']);

        $this->artisan('users:verify', ['email' => ' Someone@Example.com '])->expectsOutput('Verified someone@example.com.')->assertExitCode(0);
        $this->assertTrue($user->refresh()->hasVerifiedEmail());

        $this->artisan('users:verify', ['email' => 'someone@example.com'])->expectsOutput('someone@example.com is already verified.')->assertExitCode(0);
        $this->artisan('users:verify', ['email' => 'nobody@example.com'])->expectsOutput('No user with nobody@example.com.')->assertExitCode(1);
    }
}
