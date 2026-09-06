<?php

namespace Tests\Feature;

use App\Notifications\EmailReadinessNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailReadinessTest extends TestCase
{
    public function test_local_or_example_mail_configuration_fails_safely(): void
    {
        config([
            'app.env' => 'production',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mailhog',
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.from.address' => 'hello@example.com',
        ]);

        $this->artisan('buildpusher:email:diagnose')
            ->expectsOutputToContain('Configure a production mail transport and verified sender')
            ->assertFailed();
    }

    public function test_production_configuration_can_send_an_explicit_test_notification(): void
    {
        Notification::fake();
        config([
            'app.env' => 'production',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.mail.example.net',
            'mail.mailers.smtp.username' => 'buildpusher',
            'mail.mailers.smtp.password' => 'never-output-this-password',
            'mail.from.address' => 'notifications@buildpusher.com',
        ]);

        $this->artisan('buildpusher:email:diagnose', ['--send-to' => 'operator@example.net'])
            ->doesntExpectOutputToContain('never-output-this-password')
            ->assertSuccessful();
        Notification::assertSentOnDemand(EmailReadinessNotification::class);
    }
}
