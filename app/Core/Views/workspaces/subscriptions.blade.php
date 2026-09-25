<x-signal.layouts.platform
    :title="__('Subscriptions')"
    :description="__('Manage separate workspace plans for Deployer, Monitor, and Analytics.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Subscriptions')"
        :description="__('Each application has its own plan, access, and usage limits for this workspace.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.dashboard', $workspace)">
                {{ __('Workspace overview') }}
            </x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.team.index', $workspace)">
                {{ __('Team access') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (! $canManageBilling)
        <x-signal.ui.alert tone="info" class="mb-6">
            {{ __('You can review app access here. Plan names, billing periods, and management actions are visible to workspace owners and billing managers.') }}
        </x-signal.ui.alert>
    @elseif (! $billingDataAvailable)
        <x-signal.ui.alert tone="warning" class="mb-6">
            {{ __('Workspace billing information is temporarily unavailable.') }}
        </x-signal.ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($products as $key => $product)
            @php
                $current = $subscriptions->get($key);
                $subscription = $current?->subscription;
                $resolution = $planResolutions->get($key);
                $status = $canManageBilling ? $subscription?->status : null;
                $hasPersonalAccess = (bool) $memberProductAccess->get($key, false);
                $tone = match ($status) {
                    'active', 'trialing' => 'success',
                    'past_due', 'incomplete', 'paused' => 'warning',
                    'canceled', 'unpaid' => 'danger',
                    default => 'neutral',
                };
                $activeProductAccess = (int) $productAccessCounts->get($key, 0);
                $planLimits = $resolution?->limits ?? [];
                $planSnapshot = $resolution?->snapshot ?? [];
                $seatBilling = $key === 'deployer' && is_array($subscription?->metadata['seat_billing'] ?? null)
                    ? $subscription->metadata['seat_billing']
                    : null;
                $seatLimitIsKnown = array_key_exists('seats', $planLimits)
                    || array_key_exists('included_seats', $planSnapshot)
                    || array_key_exists('members', $planLimits);
                $seatLimit = array_key_exists('seats', $planLimits)
                    ? $planLimits['seats']
                    : (array_key_exists('included_seats', $planSnapshot)
                        ? $planSnapshot['included_seats']
                        : ($planLimits['members'] ?? null));
            @endphp

            <x-signal.ui.card class="flex min-h-full flex-col p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="ui-eyebrow">{{ __('Separate application plan') }}</p>
                        <h2 class="mt-1 text-lg font-extrabold text-ink">{{ $product['label'] }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $product['description'] }}</p>
                    </div>

                    @if ($canManageBilling)
                        <x-signal.ui.badge :tone="$subscription ? $tone : 'neutral'">
                            {{ $subscription ? str($subscription->status)->headline() : __('No plan') }}
                        </x-signal.ui.badge>
                    @else
                        <x-signal.ui.badge :tone="$hasPersonalAccess ? 'success' : 'neutral'">
                            {{ $hasPersonalAccess ? __('Access granted') : __('No access') }}
                        </x-signal.ui.badge>
                    @endif
                </div>

                <div class="mt-6 flex-1 rounded-panel border border-line bg-surface-muted/50 p-4">
                    @if ($canManageBilling)
                        <p class="text-xs font-bold uppercase tracking-wide text-subtle">{{ __('Current plan') }}</p>
                        <p class="mt-1 text-lg font-extrabold text-ink">
                            {{ $resolution?->available ? $resolution->planName : ($subscription?->plan_key ? str($subscription->plan_key)->headline() : __('Not subscribed')) }}
                        </p>

                        @if ($subscription?->current_period_ends_at)
                            <p class="mt-1 text-xs text-muted">
                                {{ __('Current period ends :date', ['date' => $subscription->current_period_ends_at->toFormattedDateString()]) }}
                            </p>
                        @elseif ($subscription?->provider === 'legacy')
                            <p class="mt-1 text-xs text-muted">{{ __('Existing access is preserved from the previous app billing setup.') }}</p>
                        @else
                            <p class="mt-1 text-xs text-muted">{{ __('This app is billed independently from the other applications.') }}</p>
                        @endif

                        @if ($seatLimitIsKnown)
                            <p class="mt-4 text-sm font-semibold text-ink">
                                @if ($seatLimit === null)
                                    {{ __(':count active app seats · unlimited', ['count' => $activeProductAccess]) }}
                                @else
                                    {{ __(':used of :limit active app seats', ['used' => $activeProductAccess, 'limit' => $seatLimit]) }}
                                @endif
                            </p>
                        @else
                            <p class="mt-4 text-sm font-semibold text-ink">{{ __(':count active app seats', ['count' => $activeProductAccess]) }}</p>
                        @endif

                        @if ($key === 'deployer' && $subscription?->provider === 'stripe')
                            @if (($seatBilling['verified'] ?? false) === true && is_int($seatBilling['additional_seats'] ?? null))
                                <p class="mt-2 text-xs leading-5 text-muted">{{ __('Deployer seat add-on quantity: :count', ['count' => $seatBilling['additional_seats']]) }}</p>
                            @else
                                <p class="mt-2 text-xs leading-5 text-muted">{{ __('Deployer seat add-on quantity is not verified from the subscription items yet.') }}</p>
                            @endif
                        @endif

                        @if ($resolution !== null && ! $resolution->available)
                            <p class="mt-2 text-xs leading-5 text-muted">{{ __('Detailed entitlements are unavailable until this app’s plan record is reconciled.') }}</p>
                        @endif
                    @else
                        <p class="text-sm font-semibold text-ink">
                            {{ $hasPersonalAccess ? __('You currently have access to this app.') : __('You currently do not have access to this app.') }}
                        </p>
                        <p class="mt-2 text-xs leading-5 text-muted">{{ __('Ask a workspace owner or billing manager for plan details or an access change.') }}</p>
                    @endif
                </div>

                @if ($canManageBilling)
                    <div class="mt-4">
                        @if ($billingLinkIssues->get($key))
                            <x-signal.ui.alert tone="warning" class="mb-3">
                                {{ $billingLinkIssues->get($key) }}
                            </x-signal.ui.alert>
                        @endif
                        @if ($billingManagementLinks->get($key))
                            <x-signal.ui.button :href="$billingManagementLinks->get($key)" variant="secondary" class="w-full">
                                {{ $resolution?->unavailableReason === 'current_subscription_missing'
                                    ? __('Set up :product billing', ['product' => $product['label']])
                                    : __('Manage :product billing', ['product' => $product['label']]) }}
                            </x-signal.ui.button>
                        @elseif ($key === 'analytics')
                            <p class="text-xs leading-5 text-muted">{{ __('Analytics has no paid billing catalog yet. Existing imported workspaces retain a separate no-charge legacy access slot.') }}</p>
                        @else
                            <p class="text-xs leading-5 text-muted">{{ __('Billing management is not available for this app right now.') }}</p>
                        @endif
                    </div>
                @endif
            </x-signal.ui.card>
        @endforeach
    </div>
</x-signal.layouts.platform>
