<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to account')"
        :route="route('account.index')"
    />

    <x-layouts.partials.heading
        :title="__('Sign-in history')"
        :description="__('Review successful password and social sign-ins retained for account security.')"
    />

    @include('components.scenes.users.sign-ins-content')
</x-layouts.app>
