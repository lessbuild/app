@extends('monitor::layouts.auth')
@section('title', 'Verify email')
@section('heading', 'Check your inbox')
@section('description', 'Verify your email address before inviting teammates or accepting invitations.')
@section('content')
    <p class="text-sm text-muted dark:text-subtle">We sent a verification link to <strong class="text-ink dark:text-ink">{{ auth()->user()->email }}</strong>.</p>
    <form method="POST" action="{{ route('monitor.verification.send') }}">@csrf<x-monitor::ui.button class="w-full">Resend verification email</x-monitor::ui.button></form>
    <a href="{{ route('monitor.dashboard') }}" class="block text-center text-xs font-bold text-primary hover:underline dark:text-primary">Back to workspace</a>
    <form method="POST" action="{{ route('monitor.logout') }}">@csrf<x-monitor::ui.button variant="secondary" class="w-full">Sign out</x-monitor::ui.button></form>
@endsection
