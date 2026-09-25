<x-signal.layouts.platform
    :title="__('API credentials')"
    :description="__('Review workspace-scoped API and ingestion credentials without exposing their secrets.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('API credentials')"
        :description="__('Review Deployer API tokens and Monitor collection keys mapped to this workspace. Core never displays credential secrets.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="secondary">{{ __('Workspace management') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.dashboard', $workspace)" variant="primary">{{ __('Workspace overview') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.alert tone="info" class="mt-6">
        {{ __('Create, rotate, and revoke credentials in the owning product. Each link uses the shared sign-in handoff and the product checks its own workspace role and policies again. A newly issued secret is shown only once by its source product.') }}
    </x-signal.ui.alert>

    @if ($hasAnalyticsAccess)
        <x-signal.ui.card class="mt-4 flex flex-wrap items-center justify-between gap-3 p-4">
            <p class="text-sm leading-6 text-muted">{{ __('Analytics site IDs are public collection identifiers, not bearer credentials. Review site setup and tracking in Analytics.') }}</p>
            @if ($analyticsUrl)
                <x-signal.ui.button :href="$analyticsUrl" variant="secondary" class="ui-btn-sm">{{ __('Open Analytics') }}</x-signal.ui.button>
            @endif
        </x-signal.ui.card>
    @endif

    <x-signal.ui.card as="form" method="GET" :action="route('core.workspace.credentials', $workspace)" class="mt-6 grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-signal.ui.select-field name="product" id="credential-product" :label="__('Product')">
            <option value="">{{ __('All products') }}</option>
            @foreach ($productOptions as $product => $label)
                <option value="{{ $product }}" @selected($selectedProduct === $product)>{{ $label }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="type" id="credential-type" :label="__('Credential type')">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $type => $label)
                <option value="{{ $type }}" @selected($selectedType === $type)>{{ $label }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="status" id="credential-status" :label="__('Status')">
            <option value="">{{ __('All statuses') }}</option>
            <option value="active" @selected($selectedStatus === 'active')>{{ __('Active') }}</option>
            <option value="expired" @selected($selectedStatus === 'expired')>{{ __('Expired') }}</option>
            <option value="revoked" @selected($selectedStatus === 'revoked')>{{ __('Revoked') }}</option>
        </x-signal.ui.select-field>
        <div class="flex flex-wrap items-end justify-end gap-2">
            <x-signal.ui.button :href="route('core.workspace.credentials', $workspace)" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Apply filters') }}</x-signal.ui.button>
        </div>
    </x-signal.ui.card>

    @if ($unavailableProducts->isNotEmpty())
        <x-signal.ui.alert tone="warning" class="mt-5" role="status">
            {{ __('Credential inventory from :products is temporarily unavailable. Open that product after its database responds again.', ['products' => $unavailableProducts->join(', ')]) }}
        </x-signal.ui.alert>
    @endif

    <section class="mt-8" aria-labelledby="credential-inventory-heading">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Product-owned credentials') }}</p>
                <h2 id="credential-inventory-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Credential inventory') }}</h2>
            </div>
            <p class="text-sm text-muted">{{ trans_choice(':count credential shown|:count credentials shown', $credentials->total(), ['count' => $credentials->total()]) }}</p>
        </div>

        @if ($credentials->isEmpty())
            <x-signal.ui.empty-state
                :title="$unavailableProducts->isNotEmpty() ? __('Some credential sources are unavailable') : __('No credentials match these filters')"
                :description="$unavailableProducts->isNotEmpty() ? __('Try again when those product databases respond. Existing source product screens remain authoritative.') : __('Credentials will appear here when you have access to mapped Deployer or Monitor resources that issue machine keys.')"
                :icon="$unavailableProducts->isNotEmpty() ? 'clock' : 'key'"
            >
                <x-slot:action>
                    <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="primary">{{ __('Workspace management') }}</x-signal.ui.button>
                </x-slot:action>
            </x-signal.ui.empty-state>
        @else
            <div class="grid gap-3">
                @foreach ($credentials as $credential)
                    @php
                        $credentialTone = match ($credential->status) {
                            'active' => 'success',
                            'expired' => 'warning',
                            default => 'neutral',
                        };
                    @endphp
                    <x-signal.ui.card as="article" class="p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-signal.ui.badge tone="neutral">{{ $credential->productLabel }}</x-signal.ui.badge>
                                    <x-signal.ui.badge tone="info">{{ $credential->type }}</x-signal.ui.badge>
                                    <x-signal.ui.badge :tone="$credentialTone">{{ $credential->statusLabel }}</x-signal.ui.badge>
                                    @if ($credential->prefix)
                                        <x-signal.ui.badge tone="neutral">{{ __('Prefix :prefix', ['prefix' => $credential->prefix]) }}</x-signal.ui.badge>
                                    @endif
                                </div>
                                <h3 class="mt-2 font-bold text-ink">{{ $credential->name }}</h3>
                                <p class="mt-1 text-sm leading-6 text-muted">{{ $credential->scope }}</p>
                                <dl class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-subtle">
                                    @if ($credential->createdAt)
                                        <div><dt class="inline font-semibold">{{ __('Created') }}:</dt> <dd class="inline"><time datetime="{{ $credential->createdAt->toIso8601String() }}">{{ $credential->createdAt->format('Y-m-d H:i T') }}</time></dd></div>
                                    @endif
                                    @if ($credential->lastUsedAt)
                                        <div><dt class="inline font-semibold">{{ __('Last used') }}:</dt> <dd class="inline"><time datetime="{{ $credential->lastUsedAt->toIso8601String() }}">{{ $credential->lastUsedAt->diffForHumans() }}</time></dd></div>
                                    @endif
                                    @if ($credential->expiresAt)
                                        <div><dt class="inline font-semibold">{{ __('Expires') }}:</dt> <dd class="inline"><time datetime="{{ $credential->expiresAt->toIso8601String() }}">{{ $credential->expiresAt->format('Y-m-d H:i T') }}</time></dd></div>
                                    @endif
                                </dl>
                            </div>
                            @if ($credential->manageUrl)
                                <x-signal.ui.button :href="$credential->manageUrl" variant="secondary" class="ui-btn-sm">{{ __('Manage in :product', ['product' => $credential->productLabel]) }}</x-signal.ui.button>
                            @endif
                        </div>
                    </x-signal.ui.card>
                @endforeach
            </div>

            @if ($credentials->hasPages())
                <nav class="mt-5" aria-label="{{ __('Credential inventory pages') }}">{{ $credentials->links() }}</nav>
            @endif
        @endif

        <p class="mt-4 text-xs leading-5 text-subtle">{{ __('This page shows metadata only. Token plaintext, hashes, signing secrets, and collector values are never sent to Core. Deployer tokens shown here belong to your Deployer account; Monitor workspace credentials are limited to mapped environments in workspaces where your Monitor role can manage them.') }}</p>
    </section>
</x-signal.layouts.platform>
