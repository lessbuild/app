@props([
    'workspace',
    'view' => null,
    'returnView' => null,
    'canShare' => false,
])

@php
    $filters = $view?->filters ?? [];
    $formId = $view?->getKey() ?? 'new';
@endphp

<form method="POST" action="{{ $view ? route('core.workspace.views.update', [$workspace, $view]) : route('core.workspace.views.store', $workspace) }}" class="grid gap-4">
    @csrf
    @if ($view)
        @method('PUT')
    @endif
    @if ($returnView)
        <input type="hidden" name="return_view" value="{{ $returnView }}">
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <x-signal.ui.field :label="__('View name')" name="name" :id="'workspace-view-name-'.$formId">
            <x-signal.ui.input name="name" :id="'workspace-view-name-'.$formId" :value="$view?->name" maxlength="80" required />
        </x-signal.ui.field>

        <x-signal.ui.field :label="__('Visibility')" name="visibility" :id="'workspace-view-visibility-'.$formId">
            <x-signal.ui.select name="visibility" :id="'workspace-view-visibility-'.$formId" required>
                <option value="personal" @selected(old('visibility', $view?->visibility ?? 'personal') === 'personal')>{{ __('Personal') }}</option>
                <option value="workspace" @selected(old('visibility', $view?->visibility ?? 'personal') === 'workspace') @disabled(! $canShare)>{{ __('Workspace (owners and admins)') }}</option>
            </x-signal.ui.select>
        </x-signal.ui.field>

        <x-signal.ui.field :label="__('Product')" name="product" :id="'workspace-view-product-'.$formId">
            <x-signal.ui.select name="product" :id="'workspace-view-product-'.$formId" required>
                <option value="all" @selected(old('product', $filters['product'] ?? 'all') === 'all')>{{ __('All products') }}</option>
                <option value="deployer" @selected(old('product', $filters['product'] ?? 'all') === 'deployer')>{{ __('Deployer') }}</option>
                <option value="monitor" @selected(old('product', $filters['product'] ?? 'all') === 'monitor')>{{ __('Monitor') }}</option>
                <option value="analytics" @selected(old('product', $filters['product'] ?? 'all') === 'analytics')>{{ __('Analytics') }}</option>
            </x-signal.ui.select>
        </x-signal.ui.field>

        <div class="flex items-center pt-5">
            <x-signal.ui.checkbox name="pinned_only" value="1" unchecked-value="0" :checked="(bool) old('pinned_only', $filters['pinned_only'] ?? false)">
                {{ __('Only projects pinned in this view’s scope') }}
            </x-signal.ui.checkbox>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <x-signal.ui.button type="submit" variant="primary">{{ $view ? __('Update view') : __('Save view') }}</x-signal.ui.button>
        @if ($view)
            <x-signal.ui.button :href="route('core.workspace.dashboard', array_filter(['workspace' => $workspace, 'view' => $returnView]))" variant="ghost">{{ __('Cancel') }}</x-signal.ui.button>
        @endif
    </div>
</form>
