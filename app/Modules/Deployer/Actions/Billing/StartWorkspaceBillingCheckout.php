<?php

namespace App\Modules\Deployer\Actions\Billing;

use App\Core\Models\BillingCustomer;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProductSubscription;
use App\Modules\Deployer\Exceptions\StripeBillingException;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Services\DeployerPlanAuthority;
use App\Modules\Deployer\Services\DeployerStripeBillingClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class StartWorkspaceBillingCheckout
{
    public function __construct(
        private readonly DeployerPlanAuthority $planAuthority,
        private readonly DeployerStripeBillingClient $stripe,
    ) {}

    /** Return a hosted checkout URL, or null when the workspace already has a subscription to manage. */
    public function handle(
        Organization $organization,
        PlatformUser $actor,
        string $workspaceId,
        string $plan,
        string $interval,
    ): ?string {
        $current = $this->planAuthority->currentSubscription($organization);
        if ($current?->provider === 'stripe'
            && $current->provider_account_key === 'deployer'
            && filled($current->provider_subscription_id)
            && in_array($current->status, ['active', 'trialing', 'past_due', 'paused', 'incomplete'], true)) {
            return null;
        }

        if ($current !== null
            && ! ($current->plan_key === 'free' && in_array($current->provider, ['deployer_legacy', 'legacy_access'], true))
            && in_array($current->status, ['active', 'trialing', 'past_due', 'paused', 'incomplete'], true)) {
            throw new StripeBillingException('This workspace has a subscription that needs billing review before another checkout can start.');
        }

        if (! $this->stripe->checkoutConfigured($plan, $interval)) {
            throw new StripeBillingException('Stripe checkout is not configured for this plan.');
        }

        $customer = $current?->billingCustomer;
        $customerId = $customer instanceof BillingCustomer
            && $customer->provider === 'stripe'
            && $customer->provider_account_key === 'deployer'
            && (string) $customer->workspace_id === $workspaceId
            ? $customer->provider_customer_id
            : null;
        $cacheKey = 'deployer:billing:checkout:'.hash('sha256', $workspaceId);

        $session = Cache::lock($cacheKey.':lock', 20)->block(5, function () use (
            $cacheKey,
            $organization,
            $workspaceId,
            $actor,
            $plan,
            $interval,
            $customerId,
        ): array {
            $pending = Cache::get($cacheKey);
            if (is_array($pending) && is_numeric($pending['created_at'] ?? null)
                && (int) $pending['created_at'] > now('UTC')->subMinutes(31)->timestamp) {
                if (($pending['plan'] ?? null) !== $plan || ($pending['interval'] ?? null) !== $interval) {
                    throw new StripeBillingException('Another workspace checkout is already open. Finish or cancel it before selecting a different plan.');
                }

                if (is_string($pending['url'] ?? null) && str_starts_with($pending['url'], 'https://')) {
                    return $pending;
                }
            } else {
                $pending = [
                    'plan' => $plan,
                    'interval' => $interval,
                    'created_at' => now('UTC')->timestamp,
                    'idempotency_key' => hash('sha256', (string) Str::uuid()),
                ];
            }

            Cache::put($cacheKey, $pending, now()->addMinutes(32));
            $hasStripeHistory = ProductSubscription::query()
                ->where('workspace_id', $workspaceId)
                ->where('product', 'deployer')
                ->where('provider', 'stripe')
                ->where('provider_account_key', 'deployer')
                ->exists();
            $session = $this->stripe->createCheckoutSession(
                $organization,
                $workspaceId,
                (string) $actor->email,
                $plan,
                $interval,
                $customerId,
                ! $hasStripeHistory,
                $pending['idempotency_key'],
            );
            $session = [...$pending, ...$session, 'created_at' => now('UTC')->timestamp];
            Cache::put($cacheKey, $session, now()->addMinutes(32));

            return $session;
        });

        return $session['url'];
    }
}
