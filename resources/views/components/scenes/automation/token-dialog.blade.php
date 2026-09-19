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
                <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Token name') }}</span>
                <input name="name" required maxlength="100" value="{{ old('name') }}" class="input secondary w-full rounded-md" placeholder="CI deployment">
                <x-forms.errors name="name" />
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Expires') }}</span>
                <select name="expires_in_days" class="input secondary w-full rounded-md">
                    <option value="30" @selected((string) old('expires_in_days', 365) === '30')>{{ __('30 days') }}</option>
                    <option value="90" @selected((string) old('expires_in_days', 365) === '90')>{{ __('90 days') }}</option>
                    <option value="180" @selected((string) old('expires_in_days', 365) === '180')>{{ __('180 days') }}</option>
                    <option value="365" @selected((string) old('expires_in_days', 365) === '365')>{{ __('1 year') }}</option>
                </select>
                <x-forms.errors name="expires_in_days" />
            </label>
        </div>
        <fieldset>
            <legend class="mb-2 text-xs font-bold uppercase text-secondary">{{ __('Abilities') }}</legend>
            <div class="flex flex-wrap gap-4 text-sm text-secondary">
                @foreach (['read', 'deploy', 'manage'] as $ability)
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="abilities[]" value="{{ $ability }}" @checked(in_array($ability, (array) old('abilities', ['read']), true))>
                        {{ ucfirst($ability) }}
                    </label>
                @endforeach
            </div>
            <x-forms.errors name="abilities" />
        </fieldset>
        <x-ui.button type="submit" variant="primary">{{ __('Create token') }}</x-ui.button>
    </form>
</x-dialogs.modal>
