@props([
    'environment',
    'context',
    'open' => false,
])

<x-signal.overlays.modal
    id="save-investigation-dialog"
    :title="__('Save this investigation')"
    :description="__('Create a named, expiring link for workspace members. Evidence is rechecked when the link is opened.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.environments.investigations.store', $environment) }}" class="space-y-4">
        @csrf
        <x-signal.ui.input type="hidden" name="_investigation_view_form" value="1" :restore="false" />
        <x-signal.ui.input type="hidden" name="window" value="{{ $context->window }}" :restore="false" />
        <x-signal.ui.input type="hidden" name="service" value="{{ $context->serviceId ?? 'all' }}" :restore="false" />
        <x-signal.ui.input type="hidden" name="deployment" value="{{ $context->deployment }}" :restore="false" />
        <x-signal.ui.input type="hidden" name="severity" value="{{ $context->severity }}" :restore="false" />
        <label class="block">
            <span class="ui-label">{{ __('Investigation name') }}</span>
            <x-signal.ui.input name="name" maxlength="60" required class="ui-input" placeholder="{{ __('Name this view') }}" value="{{ old('name') }}" autofocus :restore="false" />
            <x-forms.errors name="name" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Keep for') }}</span>
            <x-signal.ui.select name="expires_in_days" class="ui-input">
                @foreach (\App\Modules\Deployer\Models\ObservabilityInvestigationView::EXPIRY_DAYS as $days)
                    <option value="{{ $days }}" @selected((int) old('expires_in_days', \App\Modules\Deployer\Models\ObservabilityInvestigationView::DEFAULT_EXPIRY_DAYS) === $days)>{{ trans_choice(':days day|:days days', $days, ['days' => $days]) }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="expires_in_days" />
            <x-forms.errors name="expires_in_days" />
        </label>
        <x-forms.errors name="service" />
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save view') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
