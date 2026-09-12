<?php

namespace App\Http\Controllers;

use App\Actions\Billing\CreateBillingCheckoutAction;
use App\Http\Requests\BillingCheckoutRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function checkout(
        BillingCheckoutRequest $request,
        string $plan,
        CreateBillingCheckoutAction $createCheckout,
    ): mixed {
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        $interval = $request->billingInterval();
        $price = config("billing.plans.{$plan}.{$interval}_price_id");
        abort_unless(filled(config('cashier.secret')) && filled($price), 503, 'Stripe billing is not configured yet.');

        $billingUser = $organization->owner;
        if ($billingUser->subscribed('default')) {
            return redirect()->route('billing.index')->with('status', 'Use the billing portal to change your plan.');
        }

        return $createCheckout->handle($organization, $billingUser, $plan, $interval, $price);
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
