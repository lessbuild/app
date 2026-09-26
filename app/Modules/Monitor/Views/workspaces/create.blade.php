@extends('monitor::layouts.auth')
@section('title', 'New workspace')
@section('heading', 'Create a workspace')
@section('description', 'Keep applications, telemetry, and team access separate for each organisation.')
@section('content')
    <form method="POST" action="{{ route('monitor.workspaces.store') }}" class="space-y-5">
        @csrf
        <x-monitor::ui.input name="name" label="Workspace name" maxlength="120" required autofocus />
        <x-monitor::ui.button class="w-full">Create workspace</x-monitor::ui.button>
    </form>
    <form method="POST" action="{{ route('monitor.logout') }}">@csrf<x-monitor::ui.button variant="secondary" class="w-full">Sign out</x-monitor::ui.button></form>
@endsection
