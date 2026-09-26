<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Core\Models\BillingCustomer;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProductSubscription;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Modules\Deployer\Actions\Billing\CreateBillingCheckoutAction;
use App\Modules\Deployer\Actions\Billing\StartWorkspaceBillingCheckout;
use App\Modules\Deployer\Exceptions\StripeBillingException;
use App\Modules\Deployer\Http\Requests\BillingCheckoutRequest;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Services\DeployerPlanAuthority;
use App\Modules\Deployer\Services\DeployerStripeBillingClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Cashier\Checkout;
use Throwable;

class BillingController extends Controller
{
    public function __construct(
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $workspaceAccess,
        private readonly DeployerPlanAuthority $planAuthority,
    ) {}

    /** Render workspace billing using the canonical Core subscription when that authority is enabled. */
    public function index(Request $request, DeployerStripeBillingClient $stripe): View
    {
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        $billingUser = $organization->owner;
        $corePlanAuthority = $this->planAuthority->usesCore();
        $resolution = $corePlanAuthority ? $this->planAuthority->resolve($organization) : null;
        $coreSubscription = $corePlanAuthority ? $this->planAuthority->currentSubscription($organization) : null;
        $legacySubscription = $corePlanAuthority ? null : $billingUser->subscription('default');
        $subscription = $corePlanAuthority ? $coreSubscription : $legacySubscription;
        $plans = config('billing.plans');
        $currentPlan = $corePlanAuthority
            ? ($resolution?->available ? $resolution->planKey : null)
            : $billingUser->billingPlan();
        $currentPlanName = $currentPlan === null
            ? __('Plan unverified')
            : ($plans[$currentPlan]['name'] ?? __('Plan unverified'));
        $metadata = is_array($coreSubscription?->metadata) ? $coreSubscription->metadata : [];
        $currentInterval = $corePlanAuthority
            ? ($metadata['billing_interval'] ?? $this->intervalForPrice($coreSubscription?->provider_price_id, $currentPlan))
            : $billingUser->billingInterval();
        $currentInterval = in_array($currentInterval, ['monthly', 'yearly'], true) ? $currentInterval : 'monthly';
        $customer = $coreSubscription?->billingCustomer;
        $customerId = $customer instanceof BillingCustomer
            && $customer->provider === 'stripe'
            && $customer->provider_account_key === 'deployer'
            ? $customer->provider_customer_id
            : null;
        $hasSubscription = $corePlanAuthority
            ? $coreSubscription?->provider === 'stripe' && filled($coreSubscription?->provider_subscription_id)
            : $legacySubscription !== null;
        $trialEndsAt = $corePlanAuthority ? $coreSubscription?->trial_ends_at : $legacySubscription?->trial_ends_at;
        $cancelAt = $corePlanAuthority
            ? $coreSubscription?->cancel_at
            : ($legacySubscription?->onGracePeriod() ? $legacySubscription->ends_at : null);
        $stripeReady = $corePlanAuthority
            ? $stripe->configured() && collect(array_keys($plans))->contains(fn (string $plan): bool => $stripe->checkoutConfigured($plan, 'monthly') || $stripe->checkoutConfigured($plan, 'yearly'))
            : filled(config('cashier.key')) && filled(config('cashier.secret'));
        $planAvailable = ! $corePlanAuthority || $resolution?->available === true;
        $workspaceMapped = ! $corePlanAuthority || filled($resolution?->workspaceId);
        $portalAvailable = $corePlanAuthority
            ? filled($customerId) && $stripe->configured() && (bool) config('billing.portal_enabled', true)
            : filled($billingUser->stripe_id);

        return view('scenes.billing.index', [
            'plans' => $plans,
            'currentPlan' => $currentPlan,
            'currentPlanName' => $currentPlanName,
            'currentInterval' => $currentInterval,
            'selectedInterval' => $request->query('interval') === 'yearly' ? 'yearly' : 'monthly',
            'subscription' => $subscription,
            'hasSubscription' => $hasSubscription,
            'trialEndsAt' => $trialEndsAt,
            'cancelAt' => $cancelAt,
            'canManageBilling' => $this->canManageBilling($request, $organization),
            'stripeReady' => $stripeReady,
            'portalAvailable' => $portalAvailable,
            'corePlanAuthority' => $corePlanAuthority,
            'billingStatus' => $corePlanAuthority ? ($coreSubscription?->status ?? 'unverified') : ($legacySubscription?->stripe_status ?? 'inactive'),
            'planAvailable' => $planAvailable,
            'workspaceMapped' => $workspaceMapped,
            'apiLimit' => $currentPlan !== null ? ($plans[$currentPlan]['limits']['api_requests_per_minute'] ?? null) : null,
            'trialEligible' => $corePlanAuthority
                ? ! ProductSubscription::query()
                    ->where('workspace_id', $resolution?->workspaceId)
                    ->where('product', 'deployer')
                    ->where('provider', 'stripe')
                    ->where('provider_account_key', 'deployer')
                    ->exists()
                : ! $billingUser->subscriptions()->exists(),
        ]);
    }

    /** Start a workspace-scoped Core checkout, or preserve the legacy Cashier checkout during migration. */
    public function checkout(
        BillingCheckoutRequest $request,
        string $plan,
        CreateBillingCheckoutAction $createCheckout,
        StartWorkspaceBillingCheckout $startWorkspaceCheckout,
    ): mixed {
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        $interval = $request->billingInterval();
        $price = config("billing.plans.{$plan}.{$interval}_price_id")
            ?? ($interval === 'monthly' ? config("billing.plans.{$plan}.price_id") : null);
        abort_unless(filled(config('cashier.secret')) && filled($price), 503, 'Stripe billing is not configured yet.');

        if (! $this->planAuthority->usesCore()) {
            $billingUser = $organization->owner;
            if ($billingUser->subscribed('default')) {
                return redirect()->route('billing.index')->with('status', 'Use the billing portal to change your plan.');
            }

            return $createCheckout->handle($organization, $billingUser, $plan, $interval, $price);
        }

        $platformUser = $request->attributes->get('platform_user');
        abort_unless($platformUser instanceof PlatformUser && $platformUser->hasVerifiedEmail(), 403, 'Verify your email before starting billing.');
        $resolution = $this->planAuthority->resolve($organization);
        abort_unless(filled($resolution->workspaceId), 503, 'Core could not confirm this Deployer workspace for billing.');

        try {
            $checkoutUrl = $startWorkspaceCheckout->handle(
                $organization,
                $platformUser,
                $resolution->workspaceId,
                $plan,
                $interval,
            );
        } catch (LockTimeoutException) {
            return back()->withErrors(['billing' => 'Another checkout is being prepared. Try again in a moment.']);
        } catch (StripeBillingException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'The billing provider is temporarily unavailable. Try again shortly.']);
        }

        if ($checkoutUrl === null) {
            return redirect()->route('billing.index')->with('status', 'Use the billing portal to change an active subscription.');
        }

        return redirect()->away($checkoutUrl);
    }

    /** Open the Core workspace's Stripe customer portal or the legacy Cashier customer portal. */
    public function portal(Request $request, DeployerStripeBillingClient $stripe): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        abort_unless($organization instanceof Organization, 403);
        $this->authorizeBilling($request, $organization);

        if (! $this->planAuthority->usesCore()) {
            abort_unless(filled(config('cashier.secret')), 503, 'Stripe billing is not configured yet.');

            return $organization->owner->redirectToBillingPortal(route('billing.index'));
        }

        $subscription = $this->planAuthority->currentSubscription($organization);
        $customer = $subscription?->billingCustomer;
        abort_unless($customer instanceof BillingCustomer
            && $customer->provider === 'stripe'
            && $customer->provider_account_key === 'deployer'
            && (string) $customer->workspace_id === (string) $subscription?->workspace_id
            && filled($customer->provider_customer_id), 503, 'Stripe billing is not available for this workspace yet.');

        try {
            return redirect()->away($stripe->createPortalSession($customer->provider_customer_id));
        } catch (StripeBillingException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'The billing provider is temporarily unavailable. Try again shortly.']);
        }
    }

    /** Schedule period-end cancellation through Stripe or Cashier. */
    public function cancel(Request $request, DeployerStripeBillingClient $stripe): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        abort_unless($organization instanceof Organization, 403);
        $this->authorizeBilling($request, $organization);

        if (! $this->planAuthority->usesCore()) {
            $billingUser = $organization->owner;
            abort_unless($billingUser->subscribed('default'), 422);
            $billingUser->subscription('default')->cancel();

            return back()->with('status', __('Your subscription will end after the current billing period.'));
        }

        return $this->updateCoreCancellation($organization, $stripe, cancel: true);
    }

    /** Resume a period-end cancellation through Stripe or Cashier. */
    public function resume(Request $request, DeployerStripeBillingClient $stripe): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        abort_unless($organization instanceof Organization, 403);
        $this->authorizeBilling($request, $organization);

        if (! $this->planAuthority->usesCore()) {
            $subscription = $organization->owner->subscription('default');
            abort_unless($subscription?->onGracePeriod(), 422);
            $subscription->resume();

            return back()->with('status', __('Your subscription has been resumed.'));
        }

        return $this->updateCoreCancellation($organization, $stripe, cancel: false);
    }

    private function updateCoreCancellation(Organization $organization, DeployerStripeBillingClient $stripe, bool $cancel): RedirectResponse
    {
        $subscription = $this->planAuthority->currentSubscription($organization);
        abort_unless($subscription?->provider === 'stripe'
            && $subscription->provider_account_key === 'deployer'
            && filled($subscription->provider_subscription_id)
            && in_array($subscription->status, ['active', 'trialing', 'past_due'], true), 422);
        abort_if($cancel && $subscription->cancel_at !== null, 422);
        abort_if(! $cancel && $subscription->cancel_at === null, 422);

        try {
            $stripe->setCancelAtPeriodEnd($subscription->provider_subscription_id, $cancel);
        } catch (StripeBillingException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'The billing provider is temporarily unavailable. Try again shortly.']);
        }

        return back()->with('status', $cancel
            ? __('Cancellation was requested. Stripe will confirm the change shortly.')
            : __('Resume was requested. Stripe will confirm the change shortly.'));
    }

    private function authorizeBilling(Request $request, Organization $organization): void
    {
        abort_unless($this->canManageBilling($request, $organization), 403);
    }

    private function canManageBilling(Request $request, Organization $organization): bool
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $organization->permits($request->user(), 'billing');
        }

        $platformUser = $request->attributes->get('platform_user');

        return $platformUser instanceof PlatformUser
            && $this->workspaceAccess->canManageBilling($platformUser, 'deployer', 'organization', $organization->getKey());
    }

    private function intervalForPrice(?string $priceId, ?string $plan): ?string
    {
        if ($priceId === null || $plan === null) {
            return null;
        }

        foreach (['monthly', 'yearly'] as $interval) {
            $configuredPrice = config("billing.plans.{$plan}.{$interval}_price_id")
                ?? ($interval === 'monthly' ? config("billing.plans.{$plan}.price_id") : null);
            if ($configuredPrice === $priceId) {
                return $interval;
            }
        }

        return null;
    }
}
