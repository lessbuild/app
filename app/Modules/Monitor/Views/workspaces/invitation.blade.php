@extends('monitor::layouts.auth')
@section('title', 'Workspace invitation')
@section('heading', 'Your team is waiting')
@section('description', 'Review your invitation before joining.')
@section('content')
    <div class="rounded-control bg-surface-muted p-4 text-sm dark:bg-surface-muted">
        <p class="font-bold">{{ $invitation->workspace->name }}</p>
        <p class="mt-2 text-muted dark:text-subtle">Join as <span class="font-semibold">{{ $invitation->role }}</span> using {{ $invitation->email }}.</p>
    </div>
    <form method="POST" action="{{ route('monitor.invitations.accept', $token) }}">@csrf<x-monitor::ui.button class="w-full">Accept invitation</x-monitor::ui.button></form>
    <a href="{{ route('monitor.dashboard') }}" class="block text-center text-xs font-bold text-primary hover:underline dark:text-primary">Not now</a>
@endsection
