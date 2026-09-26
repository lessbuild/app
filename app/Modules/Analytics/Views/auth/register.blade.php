@extends('analytics::layouts.auth')

@section('title', 'Create an account')

@section('content')
<div>
    <p class="ui-eyebrow">Start with a clear view</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight">Create your Analytics account</h1>
    <p class="mt-2 text-sm leading-6 text-muted">Your first workspace will be ready for a website when you arrive.</p>
</div>

@if ($errors->any())
    <x-signal.ui.alert class="mt-6" tone="danger" role="alert">
        <ul class="list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-signal.ui.alert>
@endif

<form class="mt-8 space-y-5" method="POST" action="{{ route('register') }}">
    @csrf

    <x-signal.ui.input-field name="name" label="Name" :value="old('name')" required autocomplete="name" />
    <x-signal.ui.input-field name="email" label="Email address" type="email" :value="old('email')" required autocomplete="email" />
    <x-signal.ui.input-field name="password" label="Password" type="password" required autocomplete="new-password" />
    <x-signal.ui.input-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

    <x-signal.ui.button class="w-full" type="submit" variant="primary">Create account</x-signal.ui.button>
</form>

<p class="mt-8 text-center text-sm text-muted">
    Already have an account?
    <x-signal.ui.link :href="route('login')" variant="muted" size="inline">Sign in</x-signal.ui.link>
</p>
@endsection
