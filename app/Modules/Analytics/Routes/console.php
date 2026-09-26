<?php

use App\Modules\Analytics\Jobs\RecordQueueWorkerHealth;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingException;
use App\Modules\Analytics\Services\Billing\ReconcileAnalyticsBillingEvents;
use App\Modules\Analytics\Services\Billing\ReconcileAnalyticsCheckoutAttempt;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('analytics:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('analytics:process-site-deletions')->everyMinute()->withoutOverlapping();
Artisan::command('analytics:health-probe', function (): int {
    RecordQueueWorkerHealth::dispatch();

    return 0;
})->purpose('Queue a bounded liveness probe for the Analytics background worker');
Schedule::command('analytics:health-probe')->everyMinute()->withoutOverlapping();
Schedule::command('analytics:prune')->daily()->withoutOverlapping();

Artisan::command('analytics:billing:reconcile {--limit=10 : Maximum pending events to retry (1-100)} {--event= : Retry one pending Stripe event ID}', function (ReconcileAnalyticsBillingEvents $reconciler): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $eventId = $this->option('event');
    if ($limit === false || ($eventId !== null && (! is_string($eventId) || ! preg_match('/^evt_[A-Za-z0-9]+$/', $eventId)))) {
        $this->error('Provide a limit from 1 to 100 and a valid Stripe event ID.');

        return 2;
    }

    $summary = $reconciler->handle($limit, $eventId);
    $this->info(sprintf(
        'Analytics billing reconciliation: attempted %d, completed %d, failed %d, pending %d.',
        $summary['attempted'], $summary['completed'], $summary['failed'], $summary['remaining'],
    ));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Retry Analytics Stripe events awaiting Core plan reconciliation');

Artisan::command('analytics:billing:reconcile-checkout {attempt : Aged Analytics checkout attempt ULID} {--apply : Apply a provider-verified session binding}', function (ReconcileAnalyticsCheckoutAttempt $reconciler): int {
    $attemptId = $this->argument('attempt');
    if (! is_string($attemptId) || ! Str::isUlid($attemptId)) {
        $this->error('Provide a valid Analytics checkout attempt ULID.');

        return 2;
    }

    try {
        $result = $reconciler->handle($attemptId, (bool) $this->option('apply'));
    } catch (AnalyticsBillingException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    if ($result['result'] === 'provider_session_absent') {
        $this->line('Stripe returned no matching Checkout Session; the attempt remains exclusive.');

        return 3;
    }

    $this->line(sprintf(
        'Stripe returned Checkout Session %s with status %s%s.',
        $result['session_id'],
        $result['status'],
        $result['changed'] ? '; the provider binding was recorded' : '; no local change was made',
    ));

    return 0;
})->purpose('Inspect or apply provider reconciliation for an aged Analytics checkout attempt');

if (config('analytics.billing.webhooks_enabled', false)) {
    Schedule::command('analytics:billing:reconcile --limit=10')
        ->everyFifteenMinutes()
        ->withoutOverlapping(10)
        ->onOneServer();
}
