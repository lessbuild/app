@extends('monitor::layouts.auth')
@section('title', 'Sign in')
@section('heading', 'Welcome back')
@section('description', 'Sign in to your monitoring workspace.')
@section('content')
    <form method="POST" action="{{ route('monitor.login.store') }}" class="space-y-5">
        @csrf
        <x-monitor::ui.input name="email" type="email" label="Email address" autocomplete="username" required autofocus />
        <x-monitor::ui.input name="password" type="password" label="Password" autocomplete="current-password" required />
        <div class="flex items-center justify-between gap-3 text-xs">
            <x-monitor::ui.choice id="remember" name="remember" label="Remember me" :checked="old('remember', false)" />
            <a href="{{ route('monitor.password.request') }}" class="font-semibold text-primary hover:underline dark:text-primary">Forgot password?</a>
        </div>
        <x-monitor::ui.button class="w-full">Sign in</x-monitor::ui.button>
    </form>
    <p class="text-center text-xs text-muted dark:text-subtle">New to {{ config('app.name') }}? <a href="{{ route('monitor.register') }}" class="font-bold text-primary hover:underline dark:text-primary">Create an account</a></p>
@endsection
