@props([
    'open' => false,
    'widgets',
])

<x-dialogs.modal
    id="dashboard-preferences-dialog"
    :title="__('Customize dashboard')"
    :description="__('Choose which overview sections appear on your dashboard.')"
    :open="$open"
>
    <form method="POST" action="{{ route('dashboard.preferences.update') }}" class="space-y-5">
        @csrf
        @method('PATCH')
        <x-signal.ui.input type="hidden" name="_dashboard_preferences_form" value="1" :restore="false" />
        <fieldset>
            <legend class="mb-3 text-xs font-bold uppercase tracking-wide text-muted">{{ __('Dashboard sections') }}</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach(['stats' => __('Resource totals'), 'setup' => __('Setup progress'), 'status' => __('Platform status'), 'providers' => __('Provider health')] as $widget => $label)
                    <label class="flex items-center gap-2 text-sm text-ink"><x-signal.ui.input type="checkbox" name="widgets[]" value="{{ $widget }}" class="ui-check" @checked(in_array($widget, $widgets, true)) :restore="false" /><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </fieldset>
        <x-forms.errors name="widgets" />
        <x-forms.errors name="widgets.0" />
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save layout') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
