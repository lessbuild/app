<?php

namespace App\Actions\Billing;

use App\Models\Organization;
use App\Models\User;
use Laravel\Cashier\Checkout;

class CreateBillingCheckoutAction
{
    /**
     * Build and create the Cashier checkout session with workspace metadata and seat/trial rules.
     *
     * @param  Organization  $organization  Workspace whose plan and seat count are being purchased.
     * @param  User  $billingUser  Workspace owner who owns the Cashier subscription.
     * @param  string  $plan  Validated paid billing plan key.
     * @param  string  $interval  Validated monthly or yearly interval.
     * @param  string  $price  Resolved Stripe price identifier.
     * @return Checkout The created Cashier checkout response.
     */
    public function handle(
        Organization $organization,
        User $billingUser,
        string $plan,
        string $interval,
        string $price,
    ): Checkout {
        $builder = $billingUser->newSubscription('default', $price)
            ->allowPromotionCodes()
            ->withMetadata(['organization_id' => (string) $organization->id, 'plan' => $plan, 'interval' => $interval]);

        $includedSeats = config("billing.plans.{$plan}.included_seats");
        $extraSeats = is_null($includedSeats) ? 0 : max(0, $organization->members()->count() - $includedSeats);
        $seatPrice = config("billing.plans.{$plan}.{$interval}_seat_price_id");
        if ($extraSeats > 0 && filled($seatPrice)) {
            $builder->price($seatPrice, $extraSeats);
        }

        if (! $billingUser->subscriptions()->exists() && config('billing.trial_days') > 0) {
            $builder->trialDays(config('billing.trial_days'));
        }

        return $builder->checkout([
            'success_url' => route('billing.index', ['checkout' => 'success']),
            'cancel_url' => route('billing.index', ['checkout' => 'cancelled']),
        ]);
    }
}
