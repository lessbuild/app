@props([
    'environment',
    'context',
    'open' => false,
])

<x-dialogs.modal
    id="save-investigation-dialog"
    :title="__('Save this investigation')"
    :description="__('Create a named, expiring link for workspace members. Evidence is rechecked when the link is opened.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.environments.investigations.store', $environment) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_investigation_view_form" value="1">
        <input type="hidden" name="window" value="{{ $context->window }}">
        <input type="hidden" name="service" value="{{ $context->serviceId ?? 'all' }}">
        <input type="hidden" name="deployment" value="{{ $context->deployment }}">
        <input type="hidden" name="severity" value="{{ $context->severity }}">
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Investigation name') }}</span>
            <input name="name" maxlength="60" required class="input secondary w-full rounded-md" placeholder="{{ __('Name this view') }}" value="{{ old('name') }}" autofocus>
            <x-forms.errors name="name" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Keep for') }}</span>
            <select name="expires_in_days" class="input secondary w-full rounded-md">
                @foreach (\App\Models\ObservabilityInvestigationView::EXPIRY_DAYS as $days)
                    <option value="{{ $days }}" @selected((int) old('expires_in_days', \App\Models\ObservabilityInvestigationView::DEFAULT_EXPIRY_DAYS) === $days)>{{ trans_choice(':days day|:days days', $days, ['days' => $days]) }}</option>
                @endforeach
            </select>
            <x-forms.errors name="expires_in_days" />
            <x-forms.errors name="expires_in_days" />
        </label>
        <x-forms.errors name="service" />
        <x-ui.button type="submit" variant="primary">{{ __('Save view') }}</x-ui.button>
    </form>
</x-dialogs.modal>
