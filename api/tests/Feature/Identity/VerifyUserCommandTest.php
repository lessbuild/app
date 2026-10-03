<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class VerifyUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_verify_an_email_address(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'someone@example.com']);

        $this->verify(' Someone@Example.com ')->expectsOutput('Verified someone@example.com.')->assertExitCode(0);
        $this->assertTrue($user->refresh()->hasVerifiedEmail());

        $this->verify('someone@example.com')->expectsOutput('someone@example.com is already verified.')->assertExitCode(0);
        $this->verify('nobody@example.com')->expectsOutput('No user with nobody@example.com.')->assertExitCode(1);
    }

    /**
     * Run the users:verify command for an email address.
     *
     * @param  string  $email
     * @return PendingCommand
     */
    private function verify(string $email): PendingCommand
    {
        $pending = $this->artisan('users:verify', ['email' => $email]);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
