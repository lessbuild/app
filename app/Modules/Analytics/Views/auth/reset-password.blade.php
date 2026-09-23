@extends('analytics::layouts.auth')

@section('title', 'Choose a new password')

@section('content')
<div><p class="ui-eyebrow">Account recovery</p><h1 class="mt-3 text-3xl font-extrabold tracking-tight">Choose a new password</h1></div>
@if ($errors->any())<div class="mt-6 rounded-card border border-danger/30 bg-danger-soft p-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
<form class="mt-8 space-y-5" method="POST" action="{{ route('password.update') }}">@csrf<input name="token" type="hidden" value="{{ $request->route('token') }}"><div><label class="ui-label" for="email">Email address</label><input class="ui-input" id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required></div><div><label class="ui-label" for="password">New password</label><input class="ui-input" id="password" name="password" type="password" required autocomplete="new-password"></div><div><label class="ui-label" for="password_confirmation">Confirm password</label><input class="ui-input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div><button class="ui-btn ui-btn-primary w-full" type="submit">Reset password</button></form>
@endsection
