@extends('analytics::layouts.auth')

@section('title', 'Verify your email')

@section('content')
<div><p class="ui-eyebrow">One quick check</p><h1 class="mt-3 text-3xl font-extrabold tracking-tight">Verify your email address</h1><p class="mt-2 text-sm leading-6 text-muted">We sent a verification link to {{ auth()->user()->email }}. Confirm it before opening your Analytics workspace.</p></div>
@if (session('status') === 'verification-link-sent')<div class="mt-6 rounded-card border border-success/30 bg-success-soft p-3 text-sm text-success">A fresh verification link is on its way.</div>@endif
<form class="mt-8" method="POST" action="{{ route('verification.send') }}">@csrf<button class="ui-btn ui-btn-primary w-full" type="submit">Send another verification email</button></form>
<form class="mt-3 text-center" method="POST" action="{{ route('analytics.logout') }}">@csrf<button class="text-sm font-bold text-muted underline hover:text-ink" type="submit">Sign out</button></form>
@endsection
