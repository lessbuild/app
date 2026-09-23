@extends('monitor::layouts.auth')
@section('title', 'Create account')
@section('heading', 'Start your workspace')
@section('description', 'Create your account. Connect your team and applications next.')
@section('content')
    <form method="POST" action="{{ route('monitor.register.store') }}" class="space-y-4">
        @csrf
        <x-monitor::ui.input name="name" label="Your name" autocomplete="name" maxlength="120" required autofocus />
        <x-monitor::ui.input name="workspace_name" label="Workspace name" autocomplete="organization" placeholder="Your engineering team" maxlength="120" required />
        <x-monitor::ui.input name="email" type="email" label="Email address" autocomplete="username" maxlength="254" required />
        <x-monitor::ui.input name="password" type="password" label="Password · at least 12 characters" autocomplete="new-password" minlength="12" maxlength="72" required />
        <x-monitor::ui.input name="password_confirmation" type="password" label="Confirm password" autocomplete="new-password" required />
        <x-monitor::ui.button class="w-full">Create account</x-monitor::ui.button>
    </form>
    <p class="text-center text-xs text-muted dark:text-subtle">Already have an account? <a href="{{ route('monitor.login') }}" class="font-bold text-primary hover:underline dark:text-primary">Sign in</a></p>
@endsection
