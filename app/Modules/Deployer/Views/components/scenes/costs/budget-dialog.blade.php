@props([
    'budget',
    'open' => false,
])

<x-signal.overlays.modal
    id="cost-budget-dialog"
    :title="__('Edit monthly budget')"
    :description="__('This is a planning threshold for workspace infrastructure estimates, not a provider spending cap.')"
    :open="$open"
>
    <form method="POST" action="{{ route('costs.update') }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <label>
            <span class="ui-label">{{ __('Budget in USD') }}</span>
            <x-signal.ui.input id="monthly-infrastructure-budget" type="number" min="1" max="1000000" step="0.01" name="monthly_infrastructure_budget" value="{{ old('monthly_infrastructure_budget', $budget) }}" class="ui-input mt-1" autofocus :restore="false" />
            <x-forms.errors name="monthly_infrastructure_budget" />
        </label>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save budget') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
