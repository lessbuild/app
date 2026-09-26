@extends('analytics::layouts.auth')

@section('title', 'Sign in')

@section('content')
<div>
    <p class="ui-eyebrow">Welcome back</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight">Sign in to Analytics</h1>
    <p class="mt-2 text-sm leading-6 text-muted">Use your Analytics account to continue to your workspace.</p>
</div>

@if (session('status'))
    <x-signal.ui.alert class="mt-6" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
@endif

@if ($errors->any())
    <x-signal.ui.alert class="mt-6" tone="danger" role="alert">
        <div>
            <p class="font-bold">Please check your details.</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </x-signal.ui.alert>
@endif

<form class="mt-8 space-y-5" method="POST" action="{{ route('login') }}">
    @csrf

    <x-signal.ui.input-field
        name="email"
        label="Email address"
        type="email"
        :value="old('email')"
        required
        autofocus
        autocomplete="email"
    />

    <div class="grid gap-2">
        <div class="flex items-center justify-between gap-3">
            <label class="ui-label mb-0" for="password">Password</label>
            <x-signal.ui.link :href="route('password.request')" variant="muted" size="inline" class="text-xs">Forgot password?</x-signal.ui.link>
        </div>
        <x-signal.ui.input id="password" name="password" type="password" required autocomplete="current-password" />
    </div>

    <x-signal.ui.checkbox name="remember" value="on">Remember this device</x-signal.ui.checkbox>
    <x-signal.ui.button class="w-full" type="submit" variant="primary">Sign in</x-signal.ui.button>
</form>

<p class="mt-8 text-center text-sm text-muted">
    New to Analytics?
    <x-signal.ui.link :href="route('register')" variant="muted" size="inline">Create an account</x-signal.ui.link>
</p>
@endsection
