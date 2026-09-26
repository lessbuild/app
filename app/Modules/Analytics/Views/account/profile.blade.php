@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-3xl space-y-8">
    <x-signal.ui.page-header
        eyebrow="Account"
        title="Profile and security"
        description="Manage the Analytics account used to access your workspaces."
    />

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.panel as="section" class="space-y-6 p-6">
        <div>
            <p class="ui-eyebrow">Profile</p>
            <h2 class="mt-2 text-lg font-extrabold">Your details</h2>
        </div>

        <form class="space-y-5" method="POST" action="{{ route('user-profile-information.update') }}">
            @csrf
            @method('PUT')

            <x-signal.ui.input-field
                name="name"
                label="Name"
                :value="old('name', auth()->user()->name)"
                required
                autocomplete="name"
            />

            <x-signal.ui.input-field
                name="email"
                label="Email address"
                type="email"
                :value="old('email', auth()->user()->email)"
                required
                autocomplete="email"
            />

            <x-signal.ui.button type="submit" variant="primary">Save profile</x-signal.ui.button>
        </form>
    </x-signal.ui.panel>

    <x-signal.ui.panel as="section" class="space-y-6 p-6">
        <div>
            <p class="ui-eyebrow">Password</p>
            <h2 class="mt-2 text-lg font-extrabold">Change password</h2>
        </div>

        <form class="space-y-5" method="POST" action="{{ route('user-password.update') }}">
            @csrf
            @method('PUT')

            <x-signal.ui.input-field name="current_password" label="Current password" type="password" required autocomplete="current-password" />
            <x-signal.ui.input-field name="password" label="New password" type="password" required autocomplete="new-password" />
            <x-signal.ui.input-field name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />

            <x-signal.ui.button type="submit" variant="primary">Change password</x-signal.ui.button>
        </form>
    </x-signal.ui.panel>
</div>
@endsection
