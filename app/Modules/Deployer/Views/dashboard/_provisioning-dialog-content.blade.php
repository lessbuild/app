@php($provisioningTotal = array_sum($provisioningCounts))

<section data-dashboard-provisioning-content aria-labelledby="dashboard-provisioning-dialog-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow">{{ __('Infrastructure') }}</p>
            <h2 id="dashboard-provisioning-dialog-heading" class="mt-2 text-lg font-extrabold tracking-tight text-ink">{{ __('Provisioning progress') }}</h2>
            <p class="mt-1 text-sm text-muted">
                {{ trans_choice(':count resource is being prepared|:count resources are being prepared', $provisioningTotal, ['count' => $provisioningTotal]) }}
            </p>
        </div>
        <x-signal.ui.badge tone="warning">{{ __('Live workspace snapshot') }}</x-signal.ui.badge>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3">
        <x-signal.ui.card class="p-3">
            <span class="block text-xl font-extrabold text-ink">{{ $provisioningCounts['servers'] }}</span>
            <span class="text-xs font-semibold uppercase text-muted">{{ __('Servers') }}</span>
        </x-signal.ui.card>
        <x-signal.ui.card class="p-3">
            <span class="block text-xl font-extrabold text-ink">{{ $provisioningCounts['websites'] }}</span>
            <span class="text-xs font-semibold uppercase text-muted">{{ __('Websites') }}</span>
        </x-signal.ui.card>
    </div>

    <div class="mt-5 space-y-2">
        @foreach ($provisioningResources as $resource)
            @php($isServer = $resource instanceof \App\Modules\Deployer\Models\Server)
            <x-signal.ui.card as="a" tone="interactive" class="flex items-center justify-between gap-4 p-3"
                href="{{ $isServer ? route('servers.show', $resource) : route('websites.show', $resource) }}"
            >
                <span class="min-w-0">
                    <span class="block truncate font-bold text-ink">{{ $isServer ? $resource->label : $resource->name }}</span>
                    <span class="mt-1 block text-xs text-muted">{{ $isServer ? __('Server') : __('Website') }}</span>
                </span>
                <span class="shrink-0 text-right text-xs text-muted">
                    <span class="block font-semibold uppercase">{{ str($resource->provisioning_status)->replace('_', ' ') }}</span>
                    <span class="mt-1 block">{{ $resource->created_at->diffForHumans() }}</span>
                </span>
            </x-signal.ui.card>
        @endforeach
    </div>

    @if ($provisioningTotal > $provisioningResources->count())
        <p class="mt-4 text-sm text-muted">
            {{ trans_choice(':count more resource is provisioning|:count more resources are provisioning', $provisioningTotal - $provisioningResources->count(), ['count' => $provisioningTotal - $provisioningResources->count()]) }}
        </p>
    @endif

    <div class="mt-5 flex flex-wrap gap-3 text-sm font-medium">
        <a href="{{ route('servers.index', ['provisioning' => 1]) }}" class="underline">{{ __('View all provisioning servers') }}</a>
        <a href="{{ route('websites.index', ['provisioning' => 1]) }}" class="underline">{{ __('View all provisioning websites') }}</a>
    </div>
</section>
