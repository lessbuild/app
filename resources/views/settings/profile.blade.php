<x-signal.layouts.settings :title="__('Profile')" :description="__('Your name and email address across every :app service.', ['app' => config('app.name')])">
    <x-signal.ui.settings-section :title="__('Profile')" :description="__('Changing your email address asks you to verify the new one.')">
        <form method="POST" action="{{ route('user-profile-information.update') }}" class="grid gap-5 p-4 sm:p-6">
            @csrf
            @method('PUT')
            @if (session('status') === 'profile-information-updated')
                <x-signal.ui.alert tone="success" role="status">{{ __('Profile saved.') }}</x-signal.ui.alert>
            @endif
            <x-signal.ui.input-field name="name" :label="__('Name')" :value="$user->name" autocomplete="name" required error-bag="updateProfileInformation" />
            <x-signal.ui.input-field name="email" :label="__('Email address')" type="email" :value="$user->email" autocomplete="email" required error-bag="updateProfileInformation" />
            <div><x-signal.ui.button type="submit" variant="primary">{{ __('Save profile') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>
</x-signal.layouts.settings>
