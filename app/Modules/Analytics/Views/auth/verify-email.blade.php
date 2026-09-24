@extends('analytics::layouts.auth')

@section('title', 'Verify your email')

@section('content')
<div>
    <p class="ui-eyebrow">One quick check</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight">Verify your email address</h1>
    <p class="mt-2 text-sm leading-6 text-muted">We sent a verification link to {{ auth()->user()->email }}. Confirm it before opening your Analytics workspace.</p>
</div>

@if (session('status') === 'verification-link-sent')
    <x-signal.ui.alert class="mt-6" tone="success" role="status">A fresh verification link is on its way.</x-signal.ui.alert>
@endif

<div class="mt-8">
    <x-signal.ui.button variant="primary" :href="route('platform.verification.notice')" class="w-full justify-center">Manage email verification in Buildpusher Auth</x-signal.ui.button>
</div>

<form class="mt-3 text-center" method="POST" action="{{ route('analytics.logout') }}">
    @csrf
    <x-signal.ui.button class="text-sm font-bold text-muted underline hover:text-ink" variant="link" type="submit">Sign out</x-signal.ui.button>
</form>
@endsection
