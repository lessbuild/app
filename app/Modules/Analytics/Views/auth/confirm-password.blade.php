@extends('analytics::layouts.auth')

@section('title', 'Confirm your password')

@section('content')
<div><p class="ui-eyebrow">Protected action</p><h1 class="mt-3 text-3xl font-extrabold tracking-tight">Confirm your password</h1><p class="mt-2 text-sm leading-6 text-muted">For your security, confirm your password before changing team access or deleting a website.</p></div>
@if ($errors->any())<div class="mt-6 rounded-card border border-danger/30 bg-danger-soft p-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
<form class="mt-8 space-y-5" method="POST" action="{{ route('password.confirm.store') }}">@csrf<div><label class="ui-label" for="password">Password</label><input class="ui-input" id="password" name="password" type="password" required autocomplete="current-password" autofocus></div><button class="ui-btn ui-btn-primary w-full" type="submit">Confirm password</button></form>
@endsection
