@props(['open' => false])

<x-dialogs.modal
    id="account-profile-dialog"
    :title="__('Profile information')"
    :description="__('Update the name and email address associated with your account.')"
    :open="$open"
>
    <form method="POST" action="{{ route('account.profile.update') }}" class="space-y-6">
        @csrf
        @method('PATCH')

        @if (session('profile_status'))
            <div class="ui-alert ui-alert--success p-3" role="status">
                {{ session('profile_status') }}
            </div>
        @endif

        <label class="block">
            <span class="ui-label">{{ __('Name') }}</span>
            <input
                class="ui-input"
                name="name"
                type="text"
                autocomplete="name"
                value="{{ old('name', auth()->user()->name) }}"
                required
            >
        </label>
        <x-forms.errors name="name" bag="profile" />

        <label class="block">
            <span class="ui-label">{{ __('Email') }}</span>
            <input
                class="ui-input"
                name="email"
                type="email"
                autocomplete="email"
                value="{{ old('email', auth()->user()->email) }}"
                required
            >
        </label>
        <x-forms.errors name="email" bag="profile" />

        @if (auth()->user()->hasLocalPassword())
            <label class="block">
                <span class="ui-label">{{ __('Current password') }}</span>
                <input
                    class="ui-input"
                    name="current_password"
                    type="password"
                    autocomplete="current-password"
                >
            </label>
            <p class="text-sm text-muted">
                {{ __('Required only when changing your email address. Other browser sessions will be logged out after the change.') }}
            </p>
            <x-forms.errors name="current_password" bag="profile" />
        @endif

        <x-ui.button type="submit" variant="primary">{{ __('Save profile') }}</x-ui.button>
    </form>
</x-dialogs.modal>
