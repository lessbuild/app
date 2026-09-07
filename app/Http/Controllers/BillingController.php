<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Cashier\Checkout;

class BillingController extends Controller
{
    /**
     * Render current-workspace owner billing, plan intervals, subscription state, and billing-management availability.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $billingUser = $organization->owner;

        return view('scenes.billing.index', [
            'plans' => config('billing.plans'),
            'currentPlan' => $billingUser->billingPlan(),
            'currentInterval' => $billingUser->billingInterval(),
            'selectedInterval' => $request->query('interval') === 'yearly' ? 'yearly' : 'monthly',
            'subscription' => $billingUser->subscription('default'),
            'billingUser' => $billingUser,
            'canManageBilling' => $organization->permits($request->user(), 'billing'),
            'stripeReady' => filled(config('cashier.key')) && filled(config('cashier.secret')),
        ]);
    }

    /**
     * Validate a paid plan and interval, require billing access, and create checkout with applicable seats and trial.
     *
     * @return Checkout|RedirectResponse Stripe checkout, or billing settings when already subscribed.
     */
    public function checkout(Request $request, string $plan): mixed
    {
        abort_unless(array_key_exists($plan, config('billing.plans')) && $plan !== 'free', 404);

        $organization = $request->user()->currentOrganization;
        abort_unless($organization?->permits($request->user(), 'billing'), 403);
        $data = $request->validate(['interval' => ['sometimes', Rule::in(['monthly', 'yearly'])]]);
        $interval = $data['interval'] ?? 'monthly';
        $price = config("billing.plans.{$plan}.{$interval}_price_id");
        abort_unless(filled(config('cashier.secret')) && filled($price), 503, 'Stripe billing is not configured yet.');

        $billingUser = $organization->owner;
        if ($billingUser->subscribed('default')) {
            return redirect()->route('billing.index')->with('status', 'Use the billing portal to change your plan.');
        }

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

    /**
     * Require configured Stripe billing and workspace billing access, then redirect the owner's customer to the billing portal.
     */
    public function portal(Request $request): RedirectResponse
    {
        abort_unless(filled(config('cashier.secret')), 503, 'Stripe billing is not configured yet.');
        $billingUser = $request->user()->currentOrganization?->owner;
        abort_unless($request->user()->currentOrganization?->permits($request->user(), 'billing'), 403);

        return $billingUser->redirectToBillingPortal(route('billing.index'));
    }

    /**
     * Require workspace billing access and an active subscription, schedule period-end cancellation, and redirect back.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $billingUser = $request->user()->currentOrganization?->owner;
        abort_unless($request->user()->currentOrganization?->permits($request->user(), 'billing'), 403);
        abort_unless($billingUser->subscribed('default'), 422);
        $billingUser->subscription('default')->cancel();

        return back()->with('status', __('Your subscription will end after the current billing period.'));
    }

    /**
     * Require workspace billing access and a subscription in its grace period, resume it, and redirect back.
     */
    public function resume(Request $request): RedirectResponse
    {
        $billingUser = $request->user()->currentOrganization?->owner;
        abort_unless($request->user()->currentOrganization?->permits($request->user(), 'billing'), 403);
        $subscription = $billingUser->subscription('default');
        abort_unless($subscription?->onGracePeriod(), 422);
        $subscription->resume();

        return back()->with('status', __('Your subscription has been resumed.'));
    }
}
