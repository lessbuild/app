<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Exceptions\StripeBillingException;
use App\Modules\Monitor\Http\Requests\StoreBillingCheckoutRequest;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\RecordAuditLog;
use App\Modules\Monitor\Services\StripeBillingClient;
use App\Modules\Monitor\Services\WorkspaceUsage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class BillingController extends Controller
{
    public function index(
        CurrentWorkspace $currentWorkspace,
        WorkspaceUsage $usage,
        StripeBillingClient $stripe,
        MonitorPlanAuthority $planAuthority,
    ): View {
        $workspace = $currentWorkspace->get();
        $currentWorkspace->authorizeBilling($workspace);
        $plans = config('monitor.beacon.plans');
        $currentPlanKey = $workspace->plan;
        $currentPlan = $plans[$currentPlanKey] ?? $plans['free'];
        $corePlanAuthority = $planAuthority->usesCore();
        $planResolution = $corePlanAuthority ? $planAuthority->resolve($workspace) : null;
        $usageSummary = $usage->summary($workspace);

        if ($corePlanAuthority && $planResolution?->available) {
            $currentPlanKey = $planResolution->planKey ?? $currentPlanKey;
            $currentPlan = array_replace($currentPlan, $planResolution->snapshot);
            $currentPlan['event_limit'] = $planResolution->limit('events_per_month');
        } elseif ($corePlanAuthority) {
            $currentPlanKey = null;
            $currentPlan = [
                'name' => 'Plan unverified',
                'description' => 'Monitor could not confirm this workspace’s Core plan.',
                'event_limit' => null,
            ];
        }

        $paidPlanKeys = array_keys(array_filter($plans, fn (array $plan): bool => ($plan['price'] ?? 0) > 0));
        $billingStatus = $corePlanAuthority
            ? ($planResolution?->subscriptionStatus
                ?? ($planResolution?->unavailableReason === 'current_subscription_missing' ? 'inactive' : 'unverified'))
            : ($workspace->billing_status ?? 'inactive');
        $canStartCheckout = ! $corePlanAuthority || filled($planResolution?->workspaceId);
        $providerSubscriptionId = $corePlanAuthority ? $planAuthority->stripeSubscriptionId($workspace) : null;

        return view('monitor::settings.billing', [
            'plans' => $plans,
            'currentPlanKey' => $currentPlanKey,
            'currentPlan' => $currentPlan,
            'usageSummary' => $usageSummary,
            'eventsThisMonth' => $usageSummary['event_count'],
            'remainingEvents' => $usageSummary['remaining'],
            'planLimitReached' => $usageSummary['state'] === 'limit',
            'usagePercentage' => $usageSummary['percentage'],
            'usageState' => $usageSummary['state'],
            'billingOwner' => $workspace->owner,
            'stripeBillingConfigured' => $stripe->configured(),
            'checkoutPlans' => $canStartCheckout ? array_values(array_filter($paidPlanKeys, $stripe->checkoutConfigured(...))) : [],
            'portalAvailable' => $stripe->portalConfigured($workspace),
            'corePlanAuthority' => $corePlanAuthority,
            'hasActiveSubscription' => in_array($billingStatus, ['active', 'trialing', 'past_due'], true)
                && (filled($workspace->stripe_subscription_id) || filled($providerSubscriptionId)),
            'billingStatus' => $billingStatus,
        ]);
    }

    public function checkout(
        StoreBillingCheckoutRequest $request,
        CurrentWorkspace $currentWorkspace,
        StripeBillingClient $stripe,
        RecordAuditLog $audit,
        MonitorPlanAuthority $planAuthority,
    ): RedirectResponse {
        $workspace = $currentWorkspace->get();
        $currentWorkspace->authorizeBilling($workspace);
        abort_unless($request->user()?->hasVerifiedEmail(), 403, 'Verify your email before starting billing.');

        $plan = $request->validated()['plan'];
        $corePlanAuthority = $planAuthority->usesCore();
        $planResolution = $corePlanAuthority ? $planAuthority->resolve($workspace) : null;
        if ($corePlanAuthority && ! filled($planResolution?->workspaceId)) {
            abort(503, 'Core could not confirm this Monitor workspace for billing.');
        }

        $currentPlanKey = $corePlanAuthority
            ? ($planResolution?->available ? $planResolution->planKey : null)
            : $workspace->plan;
        if ($currentPlanKey !== null && $plan === $currentPlanKey) {
            return back()->with('status', 'That is already the workspace plan.');
        }

        $billingStatus = $corePlanAuthority
            ? ($planResolution?->subscriptionStatus ?? 'inactive')
            : $workspace->billing_status;
        $subscriptionId = $corePlanAuthority
            ? $planAuthority->stripeSubscriptionId($workspace)
            : $workspace->stripe_subscription_id;

        if (in_array($billingStatus, ['active', 'trialing', 'past_due', 'paused', 'incomplete'], true)
            && filled($subscriptionId)) {
            return back()->withErrors(['billing' => 'Use Manage billing to change an active subscription.']);
        }

        try {
            $checkout = Cache::lock('beacon:billing:checkout:'.$workspace->id, 20)->block(5, function () use ($workspace, $request, $stripe, $plan): array {
                $workspace->refresh();
                $pending = $workspace->billing_checkout_started_at?->greaterThan(now('UTC')->subMinutes(15));

                if ($pending && $workspace->billing_checkout_plan !== $plan) {
                    throw new StripeBillingException('A checkout for another plan is already in progress. Finish or cancel it before starting a new checkout.');
                }

                if ($pending && filled($workspace->stripe_checkout_url)) {
                    return [
                        'id' => (string) $workspace->stripe_checkout_session_id,
                        'url' => $workspace->stripe_checkout_url,
                        'reused' => true,
                    ];
                }

                $session = $stripe->createCheckoutSession($workspace, $request->user(), $plan);
                $workspace->forceFill([
                    'stripe_checkout_session_id' => $session['id'],
                    'stripe_checkout_url' => $session['url'],
                    'billing_checkout_plan' => $plan,
                    'billing_checkout_started_at' => now('UTC'),
                ])->save();

                return [...$session, 'reused' => false];
            });
        } catch (LockTimeoutException) {
            return back()->withErrors(['billing' => 'Another checkout is already being prepared. Try again in a moment.']);
        } catch (StripeBillingException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'The billing provider is temporarily unavailable. Try again shortly.']);
        }

        if (! $checkout['reused']) {
            $audit->record($workspace, $request->user(), 'billing.checkout_started', null, ['label' => 'Stripe checkout', 'plan' => $plan]);
        }

        return redirect()->away($checkout['url']);
    }

    public function portal(
        Request $request,
        CurrentWorkspace $currentWorkspace,
        StripeBillingClient $stripe,
    ): RedirectResponse {
        $workspace = $currentWorkspace->get();
        $currentWorkspace->authorizeBilling($workspace);
        abort_unless($request->user()?->hasVerifiedEmail(), 403, 'Verify your email before managing billing.');

        try {
            return redirect()->away($stripe->createPortalSession($workspace));
        } catch (StripeBillingException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'The billing provider is temporarily unavailable. Try again shortly.']);
        }
    }

    public function success(CurrentWorkspace $currentWorkspace): RedirectResponse
    {
        $workspace = $currentWorkspace->get();
        $currentWorkspace->authorizeBilling($workspace);
        $workspace->forceFill([
            'stripe_checkout_session_id' => null,
            'stripe_checkout_url' => null,
            'billing_checkout_plan' => null,
            'billing_checkout_started_at' => null,
        ])->save();

        return to_route('monitor.settings.billing')->with('status', 'Checkout completed. Your workspace plan will update when Stripe confirms the subscription.');
    }

    public function cancel(CurrentWorkspace $currentWorkspace): RedirectResponse
    {
        $workspace = $currentWorkspace->get();
        $currentWorkspace->authorizeBilling($workspace);
        $workspace->forceFill([
            'stripe_checkout_session_id' => null,
            'stripe_checkout_url' => null,
            'billing_checkout_plan' => null,
            'billing_checkout_started_at' => null,
        ])->save();

        return to_route('monitor.settings.billing')->with('status', 'Checkout was canceled. No subscription was started.');
    }
}
