<?php

use App\Modules\Deployer\Services\DeployerPlanAuthority;
use App\Modules\Deployer\Services\ReconcileDeployerCoreBillingEvents;
use Illuminate\Support\Facades\Artisan;

Artisan::command('deployer:billing:reconcile-core-events {--limit=10 : Maximum pending events to retry (1-100)} {--event= : Retry one preserved Stripe event ID, including an event held for review} {--include-review : Also retry pending events held for review}', function (
    ReconcileDeployerCoreBillingEvents $reconciler,
    DeployerPlanAuthority $planAuthority,
): int {
    if (! $planAuthority->usesCore()) {
        $this->info('Deployer Core billing is not enabled; no events were reconciled.');

        return 0;
    }

    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $eventId = $this->option('event');
    if ($limit === false) {
        $this->error('The limit must be an integer between 1 and 100.');

        return 2;
    }
    if ($eventId !== null && (! is_string($eventId) || ! preg_match('/^evt_[A-Za-z0-9]+$/', $eventId))) {
        $this->error('The event must be a Stripe event ID.');

        return 2;
    }

    $summary = $reconciler->handle($limit, $eventId, (bool) $this->option('include-review'));
    $this->info(sprintf(
        'Deployer Core billing reconciliation: attempted %d, completed %d, pending %d, held for review %d, failed %d, skipped %d.',
        $summary['attempted'],
        $summary['completed'],
        $summary['pending'],
        $summary['needs_review'],
        $summary['failed'],
        $summary['skipped'],
    ));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Reconcile verified Deployer Stripe events with Core workspace subscriptions');
