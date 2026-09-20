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
            <legend class="text-sm font-bold text-primary">{{ __('Inbox categories') }}</legend>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach (['website' => __('Websites'), 'server' => __('Servers'), 'deployment' => __('Deployments'), 'provider' => __('Providers'), 'security' => __('Security'), 'recipe' => __('Recipes')] as $value => $label)
                    <label class="flex items-center gap-3 text-sm text-secondary">
                        <input type="checkbox" name="categories[]" value="{{ $value }}" @checked(in_array($value, $selectedCategories, true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <x-forms.errors name="categories" />
            <x-forms.errors name="categories.*" />
        </fieldset>

        <input type="hidden" name="recoveries" value="0">
        <label class="flex items-start gap-3 text-sm text-secondary">
            <input type="checkbox" name="recoveries" value="1" @checked((bool) $recoveriesEnabled)>
            <span><strong class="block text-primary">{{ __('Recovery notifications') }}</strong>{{ __('Notify when a failed resource becomes healthy again.') }}</span>
        </label>
        <x-forms.errors name="recoveries" />

        <p class="text-xs leading-5 text-secondary">
            {{ __('Alert destinations are configured separately in Observability.') }}
        </p>

        <x-ui.button type="submit" variant="primary">{{ __('Save preferences') }}</x-ui.button>
    </form>
</x-dialogs.modal>
