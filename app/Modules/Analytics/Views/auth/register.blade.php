@extends('analytics::layouts.auth')

@section('title', 'Create an account')

@section('content')
<div><p class="ui-eyebrow">Start with a clear view</p><h1 class="mt-3 text-3xl font-extrabold tracking-tight">Create your Analytics account</h1><p class="mt-2 text-sm leading-6 text-muted">Your first workspace will be ready for a website when you arrive.</p></div>
@if ($errors->any())<div class="mt-6 rounded-card border border-danger/30 bg-danger-soft p-3 text-sm text-danger"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="mt-8 space-y-5" method="POST" action="{{ route('register') }}">@csrf<div><label class="ui-label" for="name">Name</label><input class="ui-input" id="name" name="name" value="{{ old('name') }}" required autocomplete="name"></div><div><label class="ui-label" for="email">Email address</label><input class="ui-input" id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"></div><div><label class="ui-label" for="password">Password</label><input class="ui-input" id="password" name="password" type="password" required autocomplete="new-password"></div><div><label class="ui-label" for="password_confirmation">Confirm password</label><input class="ui-input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div><button class="ui-btn ui-btn-primary w-full" type="submit">Create account</button></form><p class="mt-8 text-center text-sm text-muted">Already have an account? <a class="font-bold text-ink underline" href="{{ route('login') }}">Sign in</a></p>
@endsection
