@props(['open' => false])

<x-dialogs.modal
    id="automation-token-dialog"
    :title="__('Create personal access token')"
    :description="__('Use the smallest set of abilities and an explicit expiry for each integration.')"
    :open="$open"
>
    <form method="POST" action="{{ route('automation.tokens.store') }}" class="space-y-4">
        @csrf
        <x-signal.ui.input type="hidden" name="_automation_token_form" value="1" :restore="false" />
        <div class="grid gap-3 sm:grid-cols-[1fr_11rem]">
            <x-signal.ui.input-field
                id="automation-token-name"
                name="name"
                :label="__('Token name')"
                maxlength="100"
                placeholder="CI deployment"
                required
                class="w-full"
            />
            <x-signal.ui.select-field
                id="automation-token-expires-in-days"
                name="expires_in_days"
                :label="__('Expires')"
                class="w-full"
            >
                    <option value="30" @selected((string) old('expires_in_days', 365) === '30')>{{ __('30 days') }}</option>
                    <option value="90" @selected((string) old('expires_in_days', 365) === '90')>{{ __('90 days') }}</option>
                    <option value="180" @selected((string) old('expires_in_days', 365) === '180')>{{ __('180 days') }}</option>
                    <option value="365" @selected((string) old('expires_in_days', 365) === '365')>{{ __('1 year') }}</option>
            </x-signal.ui.select-field>
        </div>
        <x-signal.ui.card
            as="fieldset"
            tone="muted"
            class="p-4"
            :shadow="false"
            :aria-invalid="$errors->has('abilities') ? 'true' : 'false'"
            :aria-describedby="$errors->has('abilities') ? 'automation-token-abilities-error' : null"
        >
            <legend class="ui-eyebrow">{{ __('Abilities') }}</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                @foreach (['read', 'deploy', 'manage'] as $ability)
                    <x-signal.ui.checkbox
                        :id="'automation-token-ability-'.$ability"
                        name="abilities[]"
                        :value="$ability"
                        :checked="in_array($ability, (array) old('abilities', ['read']), true)"
                        :restore="false"
                        :error-key="false"
                        :show-errors="false"
                    >
                        {{ ucfirst($ability) }}
                    </x-signal.ui.checkbox>
                @endforeach
            </div>
            <x-forms.errors name="abilities" id="automation-token-abilities-error" />
        </x-signal.ui.card>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Create token') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
