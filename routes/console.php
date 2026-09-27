<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\ApplyEndedSelections;
use App\Domain\Billing\Actions\ReportUsage;
use App\Domain\Notifications\Actions\WarnAboutExpiringTokens;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prunable models (sign-in history and friends) drop rows past their retention.
Schedule::command('model:prune')->daily();

Artisan::command('api-tokens:warn-expiring', function (WarnAboutExpiringTokens $warn): void {
    $this->info(trans_choice('Warned :count token owner.|Warned :count token owners.', $count = $warn->handle(), ['count' => $count]));
})->purpose('Tell people about their API tokens that expire within a week');
Schedule::command('api-tokens:warn-expiring')->dailyAt('09:00');

Artisan::command('billing:apply-ended', function (ApplyEndedSelections $apply): void {
    $this->info("Ended {$apply->handle()} plan selections.");
})->purpose('Move services whose paid period has ended to their free tier');
Artisan::command('billing:report-usage', function (ReportUsage $report): void {
    $this->info("Reported {$report->handle()} usage buckets.");
})->purpose('Send new metered usage to Stripe');
Schedule::command('billing:apply-ended')->hourly();
Schedule::command('billing:report-usage')->hourlyAt(5);
