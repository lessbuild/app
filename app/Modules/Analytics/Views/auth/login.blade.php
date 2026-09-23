@extends('analytics::layouts.auth')

@section('title', 'Sign in')

@section('content')
<div><p class="ui-eyebrow">Welcome back</p><h1 class="mt-3 text-3xl font-extrabold tracking-tight">Sign in to Analytics</h1><p class="mt-2 text-sm leading-6 text-muted">Use your Analytics account to continue to your workspace.</p></div>
@if (session('status'))<div class="mt-6 rounded-card border border-success/30 bg-success-soft p-3 text-sm text-success">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="mt-6 rounded-card border border-danger/30 bg-danger-soft p-3 text-sm text-danger"><p class="font-bold">Please check your details.</p><ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="mt-8 space-y-5" method="POST" action="{{ route('login') }}">@csrf<div><label class="ui-label" for="email">Email address</label><input class="ui-input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"></div><div><div class="flex items-center justify-between"><label class="ui-label" for="password">Password</label><a class="text-xs font-bold text-muted hover:text-ink" href="{{ route('password.request') }}">Forgot password?</a></div><input class="ui-input" id="password" name="password" type="password" required autocomplete="current-password"></div><label class="flex items-center gap-2 text-sm text-muted"><input class="rounded border-line" name="remember" type="checkbox"> Remember this device</label><button class="ui-btn ui-btn-primary w-full" type="submit">Sign in</button></form><p class="mt-8 text-center text-sm text-muted">New to Analytics? <a class="font-bold text-ink underline" href="{{ route('register') }}">Create an account</a></p>
@endsection
