@props([
    'open' => false,
    'organization',
])

@php
    $defaultCategories = ['website', 'server', 'deployment', 'provider', 'security', 'recipe'];
    $enabledCategories = $organization->notification_preferences['categories'] ?? $defaultCategories;
    $selectedCategories = old('categories', $enabledCategories);
    $selectedCategories = is_array($selectedCategories) ? $selectedCategories : [];
    $recoveriesEnabled = old('recoveries', $organization->notification_preferences['recoveries'] ?? true);
@endphp

<x-dialogs.modal
    id="organization-notification-preferences-dialog"
    :title="__('Notification preferences')"
    :description="__('Choose which events create inbox notifications for this workspace.')"
    :open="$open"
>
    <form method="POST" action="{{ route('organizations.notification-preferences.update') }}" class="space-y-5">
        @csrf
        @method('PATCH')

        <fieldset>
            <legend class="text-sm font-bold text-ink">{{ __('Inbox categories') }}</legend>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach (['website' => __('Websites'), 'server' => __('Servers'), 'deployment' => __('Deployments'), 'provider' => __('Providers'), 'security' => __('Security'), 'recipe' => __('Recipes')] as $value => $label)
                    <label class="flex items-center gap-3 text-sm text-ink">
                        <x-signal.ui.input type="checkbox" name="categories[]" value="{{ $value }}" class="ui-check" @checked(in_array($value, $selectedCategories, true)) :restore="false" />
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <x-forms.errors name="categories" />
            <x-forms.errors name="categories.*" />
        </fieldset>

        <x-signal.ui.input type="hidden" name="recoveries" value="0" :restore="false" />
        <label class="flex items-start gap-3 text-sm text-ink">
            <x-signal.ui.input type="checkbox" name="recoveries" value="1" class="ui-check" @checked((bool) $recoveriesEnabled) :restore="false" />
            <span><strong class="block text-ink">{{ __('Recovery notifications') }}</strong>{{ __('Notify when a failed resource becomes healthy again.') }}</span>
        </label>
        <x-forms.errors name="recoveries" />

        <p class="text-xs leading-5 text-muted">
            {{ __('Alert destinations are configured separately in Observability.') }}
        </p>

        <x-signal.ui.button type="submit" variant="primary">{{ __('Save preferences') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
