@extends('monitor::layouts.auth')
@section('title', 'Choose a password')
@section('heading', 'Choose a new password')
@section('description', 'Use at least 12 characters to protect your account.')
@section('content')
    <form method="POST" action="{{ route('monitor.password.update') }}" class="space-y-5">
        @csrf
        <x-signal.ui.input type="hidden" name="token" value="{{ $token }}" :restore="false" />
        <x-monitor::ui.input name="email" type="email" label="Email address" :value="$email" autocomplete="username" required />
        <x-monitor::ui.input name="password" type="password" label="New password" autocomplete="new-password" minlength="12" maxlength="72" required autofocus />
        <x-monitor::ui.input name="password_confirmation" type="password" label="Confirm password" autocomplete="new-password" required />
        <x-monitor::ui.button class="w-full">Reset password</x-monitor::ui.button>
    </form>
@endsection
