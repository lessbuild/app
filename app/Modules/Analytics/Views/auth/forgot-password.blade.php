@extends('analytics::layouts.auth')

@section('title', 'Reset your password')

@section('content')
<div><p class="ui-eyebrow">Account recovery</p><h1 class="mt-3 text-3xl font-extrabold tracking-tight">Reset your password</h1><p class="mt-2 text-sm leading-6 text-muted">Enter your email and we’ll send a reset link.</p></div>
@if (session('status'))<div class="mt-6 rounded-card border border-success/30 bg-success-soft p-3 text-sm text-success">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="mt-6 rounded-card border border-danger/30 bg-danger-soft p-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
<form class="mt-8 space-y-5" method="POST" action="{{ route('password.email') }}">@csrf<div><label class="ui-label" for="email">Email address</label><input class="ui-input" id="email" name="email" type="email" required autofocus autocomplete="email"></div><button class="ui-btn ui-btn-primary w-full" type="submit">Email reset link</button></form><p class="mt-8 text-center text-sm text-muted"><a class="font-bold text-ink underline" href="{{ route('login') }}">Return to sign in</a></p>
@endsection
