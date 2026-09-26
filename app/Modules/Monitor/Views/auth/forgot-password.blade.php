@extends('monitor::layouts.auth')
@section('title', 'Reset password')
@section('heading', 'Forgot your password?')
@section('description', 'Enter your account email and we will send a reset link.')
@section('content')
    <form method="POST" action="{{ route('monitor.password.email') }}" class="space-y-5">
        @csrf
        <x-monitor::ui.input name="email" type="email" label="Email address" autocomplete="email" required autofocus />
        <x-monitor::ui.button class="w-full">Send reset link</x-monitor::ui.button>
    </form>
    <a href="{{ route('monitor.login') }}" class="block text-center text-xs font-bold text-primary hover:underline dark:text-primary">Back to sign in</a>
@endsection
