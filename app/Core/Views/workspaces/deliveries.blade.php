<x-signal.layouts.platform
    :title="__('Delivery history')"
    :description="__('Review recent webhook deliveries across the products you can access.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Webhook delivery history')"
        :description="__('A workspace-wide view of Deployer repository events and Monitor signed alert notifications. Open a record in its product for full details and permitted actions.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.workflows', $workspace)" variant="secondary">{{ __('Workflow activity') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.dashboard', $workspace)" variant="primary">{{ __('Workspace overview') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.card as="form" method="GET" :action="route('core.workspace.deliveries', $workspace)" class="mt-7 grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-signal.ui.select-field name="product" id="delivery-product" :label="__('Product')">
            <option value="">{{ __('All products') }}</option>
            @foreach ($productOptions as $product => $label)
                <option value="{{ $product }}" @selected($selectedProduct === $product)>{{ $label }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="status" id="delivery-status" :label="__('Delivery status')">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $status => $label)
                <option value="{{ $status }}" @selected($selectedStatus === $status)>{{ $label }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <div class="flex flex-wrap items-end justify-end gap-2">
            <x-signal.ui.button :href="route('core.workspace.deliveries', $workspace)" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Apply filters') }}</x-signal.ui.button>
        </div>
    </x-signal.ui.card>

    @if ($unavailableProducts->isNotEmpty())
        <x-signal.ui.alert tone="warning" class="mt-5" role="status">
            {{ __('Recent delivery records from :products are temporarily unavailable. Other connected products remain visible.', ['products' => $unavailableProducts->join(', ')]) }}
        </x-signal.ui.alert>
    @endif

    <section class="mt-8" aria-labelledby="delivery-history-heading">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Authorized product activity') }}</p>
                <h2 id="delivery-history-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Recent deliveries') }}</h2>
            </div>
            <p class="text-sm text-muted">{{ trans_choice(':count delivery shown|:count deliveries shown', $deliveries->total(), ['count' => $deliveries->total()]) }}</p>
        </div>

        @if ($deliveries->isEmpty())
            <x-signal.ui.empty-state
                :title="$unavailableProducts->isNotEmpty() ? __('Some delivery sources are unavailable') : __('No webhook deliveries match these filters')"
                :description="$unavailableProducts->isNotEmpty() ? __('Try again when those product databases respond. Delivery details remain available in the source product.') : __('New repository events and signed Monitor alert notifications will appear here when they are recorded for projects you can access.')"
                :icon="$unavailableProducts->isNotEmpty() ? 'clock' : 'link'"
            >
                <x-slot:action>
                    <x-signal.ui.button :href="route('core.projects.index', $workspace)" variant="primary">{{ __('Open projects') }}</x-signal.ui.button>
                </x-slot:action>
            </x-signal.ui.empty-state>
        @else
            <div class="grid gap-3">
                @foreach ($deliveries as $delivery)
                    @php
                        $deliveryTone = match ($delivery->status) {
                            'accepted', 'received' => 'success',
                            'queued', 'pending', 'sending', 'retrying' => 'warning',
                            'failed', 'unavailable', 'uncertain' => 'danger',
                            default => 'neutral',
                        };
                    @endphp
                    <x-signal.ui.card as="article" class="p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-signal.ui.badge tone="neutral">{{ $delivery->productLabel }}</x-signal.ui.badge>
                                    <x-signal.ui.badge :tone="$deliveryTone">{{ $delivery->statusLabel }}</x-signal.ui.badge>
                                    @if ($delivery->attemptCount !== null)
                                        <x-signal.ui.badge tone="neutral">{{ trans_choice(':count attempt|:count attempts', $delivery->attemptCount, ['count' => $delivery->attemptCount]) }}</x-signal.ui.badge>
                                    @endif
                                </div>
                                <h3 class="mt-2 font-bold text-ink">{{ $delivery->title }}</h3>
                                <p class="mt-1 text-sm text-muted">{{ __('Project: :project', ['project' => $delivery->projectName]) }}</p>
                                <time class="mt-2 block text-xs text-subtle" datetime="{{ $delivery->recordedAt->toIso8601String() }}">{{ $delivery->recordedAt->diffForHumans() }}</time>
                            </div>
                            @if ($delivery->resultUrl)
                                <x-signal.ui.button :href="$delivery->resultUrl" variant="secondary" class="ui-btn-sm">{{ __('Open :product', ['product' => $delivery->productLabel]) }}</x-signal.ui.button>
                            @endif
                        </div>
                    </x-signal.ui.card>
                @endforeach
            </div>

            @if ($deliveries->hasPages())
                <nav class="mt-5" aria-label="{{ __('Delivery history pages') }}">{{ $deliveries->links() }}</nav>
            @endif
        @endif

        <p class="mt-4 text-xs leading-5 text-subtle">{{ __('This page shows up to 100 recent records from each available product. Payloads, endpoint URLs, signing secrets, and provider responses are not copied into Core. Product links stay source-owned and recheck access when opened.') }}</p>
    </section>
</x-signal.layouts.platform>
