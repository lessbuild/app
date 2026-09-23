@extends('analytics::layouts.auth')

@section('title', 'Analytics for your website')

@section('content')
<div class="mx-auto max-w-2xl text-center lg:py-12">
    <span class="ui-eyebrow">Buildpusher Analytics</span>
    <h1 class="mt-5 text-4xl font-extrabold tracking-tight text-ink sm:text-6xl">Understand the work your website is doing.</h1>
    <p class="mx-auto mt-5 max-w-xl text-base leading-7 text-muted sm:text-lg">A focused, privacy-aware view of visitors, pages, campaigns, and conversion goals. Built for teams that want useful answers without a noisy dashboard.</p>
    <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row"><a class="ui-btn ui-btn-primary" href="{{ route('register') }}">Create an account</a><a class="ui-btn ui-btn-secondary" href="{{ route('login') }}">Sign in</a></div>
    <div class="mt-12 grid gap-3 text-left sm:grid-cols-3"><div class="ui-panel p-5"><p class="text-2xl">◌</p><h2 class="mt-4 font-extrabold">Cookieless estimates</h2><p class="mt-2 text-sm leading-6 text-muted">See useful trends without building a cross-site identity profile.</p></div><div class="ui-panel p-5"><p class="text-2xl">↗</p><h2 class="mt-4 font-extrabold">Clear attribution</h2><p class="mt-2 text-sm leading-6 text-muted">Understand which pages, referrers, and campaigns bring people in.</p></div><div class="ui-panel p-5"><p class="text-2xl">◎</p><h2 class="mt-4 font-extrabold">Goals that matter</h2><p class="mt-2 text-sm leading-6 text-muted">Turn important paths and events into conversion signals.</p></div></div>
</div>
@endsection
