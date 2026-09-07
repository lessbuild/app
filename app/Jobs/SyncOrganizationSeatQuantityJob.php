<?php

namespace App\Jobs;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncOrganizationSeatQuantityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Capture the workspace whose active subscription should reflect its current membership.
     *
     * @param  int  $organizationId  Workspace identifier whose owner subscription and member count will be reconciled.
     */
    public function __construct(public readonly int $organizationId) {}

    /**
     * Reconcile extra-seat prices and quantities for a valid subscription with configured seat allowances; remove obsolete seat prices and skip unbillable workspaces.
     */
    public function handle(): void
    {
        $organization = Organization::query()->with('owner')->find($this->organizationId);
        $subscription = $organization?->owner?->subscription('default');
        if (! $organization || ! $subscription || ! $subscription->valid()) {
            return;
        }

        $planKey = $organization->owner->billingPlan();
        $plan = config("billing.plans.{$planKey}", []);
        $included = $plan['included_seats'] ?? null;
        if (is_null($included)) {
            return;
        }

        $targetPrice = $plan[$organization->owner->billingInterval().'_seat_price_id'] ?? null;
        $extraSeats = max(0, $organization->members()->count() - $included);
        $knownSeatPrices = collect(config('billing.plans'))
            ->flatMap(fn (array $details): array => [
                $details['monthly_seat_price_id'] ?? null,
                $details['yearly_seat_price_id'] ?? null,
            ])->filter()->unique();

        if ($extraSeats > 0 && filled($targetPrice)) {
            if ($subscription->hasPrice($targetPrice)) {
                $subscription->updateQuantity($extraSeats, $targetPrice);
            } else {
                $subscription->addPrice($targetPrice, $extraSeats);
            }
        }

        foreach ($knownSeatPrices as $seatPrice) {
            if ($subscription->hasPrice($seatPrice) && ($seatPrice !== $targetPrice || $extraSeats === 0)) {
                $subscription->removePrice($seatPrice);
            }
        }
    }
}
