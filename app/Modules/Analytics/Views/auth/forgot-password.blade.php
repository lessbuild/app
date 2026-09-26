@extends('analytics::layouts.auth')

@section('title', 'Reset your password')

@section('content')
<div>
    <p class="ui-eyebrow">Account recovery</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight">Reset your password</h1>
    <p class="mt-2 text-sm leading-6 text-muted">Enter your email and we’ll send a reset link.</p>
</div>

@if (session('status'))
    <x-signal.ui.alert class="mt-6" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
@endif

@if ($errors->any())
    <x-signal.ui.alert class="mt-6" tone="danger" role="alert">{{ $errors->first() }}</x-signal.ui.alert>
@endif

<form class="mt-8 space-y-5" method="POST" action="{{ route('password.email') }}">
    @csrf
    <x-signal.ui.input-field name="email" label="Email address" type="email" required autofocus autocomplete="email" />
    <x-signal.ui.button class="w-full" type="submit" variant="primary">Email reset link</x-signal.ui.button>
</form>

<p class="mt-8 text-center text-sm text-muted">
    <x-signal.ui.link :href="route('login')" variant="muted" size="inline">Return to sign in</x-signal.ui.link>
</p>
@endsection
