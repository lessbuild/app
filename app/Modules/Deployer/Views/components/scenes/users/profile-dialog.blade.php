@props(['open' => false])

<x-signal.overlays.modal
    id="account-profile-dialog"
    :title="__('Profile information')"
    :description="__('Update the name and email address associated with your account.')"
    :open="$open"
>
    <form method="POST" action="{{ route('account.profile.update') }}" class="space-y-6">
        @csrf
        @method('PATCH')

        @if (session('profile_status'))
            <x-signal.ui.alert tone="success" class="p-3" role="status">
                {{ session('profile_status') }}
            </x-signal.ui.alert>
        @endif

        <label class="block">
            <span class="ui-label">{{ __('Name') }}</span>
            <x-signal.ui.input
                class="ui-input"
                name="name"
                type="text"
                autocomplete="name"
                value="{{ old('name', auth()->user()->name) }}"
                required :restore="false" />
        </label>
        <x-forms.errors name="name" bag="profile" />

        <label class="block">
            <span class="ui-label">{{ __('Email') }}</span>
            <x-signal.ui.input
                class="ui-input"
                name="email"
                type="email"
                autocomplete="email"
                value="{{ old('email', auth()->user()->email) }}"
                required :restore="false" />
        </label>
        <x-forms.errors name="email" bag="profile" />

        @if (auth()->user()->hasLocalPassword())
            <label class="block">
                <span class="ui-label">{{ __('Current password') }}</span>
                <x-signal.ui.input
                    class="ui-input"
                    name="current_password"
                    type="password"
                    autocomplete="current-password" :restore="false" />
            </label>
            <p class="text-sm text-muted">
                {{ __('Required only when changing your email address. Other browser sessions will be logged out after the change.') }}
            </p>
            <x-forms.errors name="current_password" bag="profile" />
        @endif

        <x-signal.ui.button type="submit" variant="primary">{{ __('Save profile') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
