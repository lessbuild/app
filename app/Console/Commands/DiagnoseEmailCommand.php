<?php

namespace App\Console\Commands;

use App\Notifications\EmailReadinessNotification;
use App\Services\EmailReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class DiagnoseEmailCommand extends Command
{
    protected $signature = 'buildpusher:email:diagnose
        {--json : Emit machine-readable JSON}
        {--send-to= : Send a live test notification to this address}';

    protected $description = 'Verify production email configuration and optionally send a delivery test';

    /**
     * Inspect email readiness and optionally send a test notification to a validated address when configuration is ready.
     *
     * @param  EmailReadiness  $readiness  Email transport configuration and readiness inspector.
     * @return int SUCCESS for ready configuration and successful requested delivery, otherwise FAILURE.
     */
    public function handle(EmailReadiness $readiness): int
    {
        $check = $readiness->check();
        if ($this->option('send-to')) {
            if (! $check['passed']) {
                $this->error('Email is not configured for production delivery. No test was sent.');

                return self::FAILURE;
            }
            $address = filter_var($this->option('send-to'), FILTER_VALIDATE_EMAIL);
            if ($address === false) {
                $this->error('Enter a valid test recipient address.');

                return self::INVALID;
            }
            Notification::route('mail', $address)->notify(new EmailReadinessNotification);
            $check['detail'] = 'Production configuration passed and a test notification was accepted';
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($check, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Status', 'Detail'], [[$check['name'], $check['passed'] ? 'PASS' : 'FAIL', $check['detail']]]);
        }

        return $check['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
