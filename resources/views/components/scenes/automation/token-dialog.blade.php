@props(['open' => false])

<x-dialogs.modal
    id="automation-token-dialog"
    :title="__('Create personal access token')"
    :description="__('Use the smallest set of abilities and an explicit expiry for each integration.')"
    :open="$open"
>
    <form method="POST" action="{{ route('automation.tokens.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_automation_token_form" value="1">
        <div class="grid gap-3 sm:grid-cols-[1fr_11rem]">
            <label class="block">
                <span class="ui-label">{{ __('Token name') }}</span>
                <input name="name" required maxlength="100" value="{{ old('name') }}" class="ui-input" placeholder="CI deployment">
                <x-forms.errors name="name" />
            </label>
            <label class="block">
                <span class="ui-label">{{ __('Expires') }}</span>
                <select name="expires_in_days" class="ui-input">
                    <option value="30" @selected((string) old('expires_in_days', 365) === '30')>{{ __('30 days') }}</option>
                    <option value="90" @selected((string) old('expires_in_days', 365) === '90')>{{ __('90 days') }}</option>
                    <option value="180" @selected((string) old('expires_in_days', 365) === '180')>{{ __('180 days') }}</option>
                    <option value="365" @selected((string) old('expires_in_days', 365) === '365')>{{ __('1 year') }}</option>
                </select>
                <x-forms.errors name="expires_in_days" />
            </label>
        </div>
        <fieldset>
            <legend class="ui-label">{{ __('Abilities') }}</legend>
            <div class="flex flex-wrap gap-4 text-sm text-muted">
                @foreach (['read', 'deploy', 'manage'] as $ability)
                    <label class="flex items-center gap-2 text-ink">
                        <input class="ui-check" type="checkbox" name="abilities[]" value="{{ $ability }}" @checked(in_array($ability, (array) old('abilities', ['read']), true))>
                        {{ ucfirst($ability) }}
                    </label>
                @endforeach
            </div>
            <x-forms.errors name="abilities" />
        </fieldset>
        <x-ui.button type="submit" variant="primary">{{ __('Create token') }}</x-ui.button>
    </form>
</x-dialogs.modal>
