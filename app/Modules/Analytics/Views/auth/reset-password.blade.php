@extends('analytics::layouts.auth')

@section('title', 'Choose a new password')

@section('content')
<div>
    <p class="ui-eyebrow">Account recovery</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight">Choose a new password</h1>
</div>

@if ($errors->any())
    <x-signal.ui.alert class="mt-6" tone="danger" role="alert">{{ $errors->first() }}</x-signal.ui.alert>
@endif

<form class="mt-8 space-y-5" method="POST" action="{{ route('password.update') }}">
    @csrf
    <x-signal.ui.input type="hidden" name="token" :value="$request->route('token')" />

    <x-signal.ui.input-field name="email" label="Email address" type="email" :value="old('email', $request->email)" required autocomplete="email" />
    <x-signal.ui.input-field name="password" label="New password" type="password" required autocomplete="new-password" />
    <x-signal.ui.input-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

    <x-signal.ui.button class="w-full" type="submit" variant="primary">Reset password</x-signal.ui.button>
</form>
@endsection
