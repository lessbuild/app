<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Core\Models\AnalyticsCheckoutAttempt;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\PlatformUser;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsPlanAuthority;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingAccess;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingCatalog;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingCheckout;
use App\Modules\Analytics\Services\Billing\AnalyticsBillingException;
use App\Modules\Analytics\Services\Billing\AnalyticsStripeClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class AnalyticsBillingController extends Controller
{
    public function index(
        Request $request,
        Workspace $workspace,
        AnalyticsBillingAccess $access,
        AnalyticsBillingCatalog $catalog,
        AnalyticsPlanAuthority $authority,
        AnalyticsBillingCheckout $billing,
        AnalyticsStripeClient $stripe,
    ): View {
        $context = $access->authorize($workspace, $this->actor($request));
        $resolution = $authority->resolve($workspace);
        $current = CurrentProductSubscription::query()
            ->with('subscription.billingCustomer')
            ->where('workspace_id', $context->coreWorkspace->getKey())
            ->where('product', 'analytics')
            ->first();
        $subscription = $current?->subscription;
        $webhookSecret = config('analytics.billing.stripe.webhook_secret');
        $signedWebhooksConfigured = config('analytics.billing.webhooks_enabled', false)
            && is_string($webhookSecret)
            && preg_match('/^whsec_[A-Za-z0-9]+$/', $webhookSecret);
        $boundPaidSubscription = $subscription !== null
            && $subscription->product === 'analytics'
            && $subscription->provider === 'stripe'
            && $subscription->provider_account_key === $context->providerAccountKey
            && (string) $subscription->workspace_id === (string) $context->coreWorkspace->getKey()
            && filled($subscription->provider_subscription_id)
            && filled($subscription->billing_customer_id)
            && $subscription->billingCustomer?->provider === 'stripe'
            && $subscription->billingCustomer?->provider_account_key === $context->providerAccountKey
            && $subscription->billingCustomer?->status === 'active'
            && (string) $subscription->billingCustomer?->workspace_id === (string) $context->coreWorkspace->getKey()
            && filled($subscription->billingCustomer?->provider_customer_id);

        return view('analytics::workspaces.billing', [
            'workspace' => $workspace,
            'plans' => $catalog->plans(),
            'resolution' => $resolution,
            'currentSubscription' => $boundPaidSubscription ? $subscription : null,
            'checkoutAvailable' => $billing->purchaseAvailable($workspace, $context->coreWorkspace, $context->providerAccountKey),
            'managementAvailable' => $signedWebhooksConfigured
                && $stripe->configured()
                && $boundPaidSubscription,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function checkout(
        Request $request,
        Workspace $workspace,
        AnalyticsBillingCheckout $billing,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        try {
            $session = $billing->start(
                $workspace,
                $actor,
                $validated['plan'],
                $validated['idempotency_key'],
                route('analytics.workspaces.billing.success', $workspace).'?session_id={CHECKOUT_SESSION_ID}',
                route('analytics.workspaces.billing.canceled', $workspace),
            );

            return redirect()->away($session['url']);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (AnalyticsBillingException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'Analytics billing is temporarily unavailable. Please try again.']);
        }
    }

    public function portal(
        Request $request,
        Workspace $workspace,
        AnalyticsBillingCheckout $billing,
    ): RedirectResponse {
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        try {
            $session = $billing->portal(
                $workspace,
                $this->actor($request),
                route('analytics.workspaces.billing', $workspace),
                $validated['idempotency_key'],
            );

            return redirect()->away($session['url']);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (AnalyticsBillingException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'Analytics billing is temporarily unavailable. Please try again.']);
        }
    }

    public function cancelSubscription(
        Request $request,
        Workspace $workspace,
        AnalyticsBillingCheckout $billing,
    ): RedirectResponse {
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        try {
            $billing->cancel($workspace, $this->actor($request), true, $validated['idempotency_key']);

            return to_route('analytics.workspaces.billing', $workspace)
                ->with('status', 'Cancellation was requested. Your plan will update after provider confirmation.');
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (AnalyticsBillingException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'Analytics billing is temporarily unavailable. Please try again.']);
        }
    }

    public function success(Request $request, Workspace $workspace, AnalyticsBillingAccess $access): RedirectResponse
    {
        $context = $access->authorize($workspace, $this->actor($request));
        $sessionId = $request->query('session_id');
        abort_unless(is_string($sessionId) && preg_match('/^cs_(test_|live_)?[A-Za-z0-9]+$/', $sessionId), 404);

        abort_unless(AnalyticsCheckoutAttempt::query()
            ->where('core_workspace_id', $context->coreWorkspace->getKey())
            ->where('analytics_workspace_id', (string) $workspace->getKey())
            ->where('provider_account_key', $context->providerAccountKey)
            ->where('provider_checkout_session_id', $sessionId)
            ->exists(), 404);

        // The return URL only acknowledges the browser round trip. Only a verified
        // subscription webhook may change the Core Analytics plan assignment.
        return to_route('analytics.workspaces.billing', $workspace)
            ->with('status', 'Checkout returned. Your plan will update after provider confirmation.');
    }

    public function canceled(Request $request, Workspace $workspace, AnalyticsBillingAccess $access): RedirectResponse
    {
        $access->authorize($workspace, $this->actor($request));

        return to_route('analytics.workspaces.billing', $workspace)
            ->with('status', 'Checkout was canceled. Your plan has not changed.');
    }

    private function actor(Request $request): PlatformUser
    {
        $actor = $request->attributes->get('platform_user');
        abort_unless($actor instanceof PlatformUser && $actor->hasVerifiedEmail(), 403);

        return $actor;
    }
}
