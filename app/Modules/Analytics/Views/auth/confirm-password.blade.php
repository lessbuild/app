@extends('analytics::layouts.auth')

@section('title', 'Confirm your password')

@section('content')
<div>
    <p class="ui-eyebrow">Protected action</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight">Confirm your password</h1>
    <p class="mt-2 text-sm leading-6 text-muted">For your security, confirm your password before changing team access or deleting a website.</p>
</div>

@if ($errors->any())
    <x-signal.ui.alert class="mt-6" tone="danger" role="alert">{{ $errors->first() }}</x-signal.ui.alert>
@endif

<form class="mt-8 space-y-5" method="POST" action="{{ route('password.confirm.store') }}">
    @csrf
    <x-signal.ui.input-field name="password" label="Password" type="password" required autocomplete="current-password" autofocus />
    <x-signal.ui.button class="w-full" type="submit" variant="primary">Confirm password</x-signal.ui.button>
</form>
@endsection
