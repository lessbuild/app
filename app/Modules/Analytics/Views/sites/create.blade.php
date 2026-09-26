@extends('analytics::layouts.app')

@section('content')
@php
    $timezones = [
        'UTC' => 'UTC',
        'America/New_York' => 'America/New_York',
        'America/Los_Angeles' => 'America/Los_Angeles',
        'Europe/London' => 'Europe/London',
        'Europe/Berlin' => 'Europe/Berlin',
        'Asia/Singapore' => 'Asia/Singapore',
    ];
@endphp

<div class="mx-auto max-w-2xl space-y-8">
    <x-signal.ui.page-header
        :eyebrow="__('Website setup')"
        :title="__('Add a website')"
        :description="__('Start with the domain where your public site runs. You can add more domains after the first verification.')"
    />

    @if (! $plan->available)
        <x-signal.ui.alert tone="warning" role="status">
            {{ __('Analytics could not confirm this workspace plan. Site creation is paused until billing access is available.') }}
        </x-signal.ui.alert>
    @elseif (! $plan->allows('site_management'))
        <x-signal.ui.alert tone="warning" role="status">
            {{ __('The current Analytics plan does not include site management.') }}
        </x-signal.ui.alert>
    @elseif (! $siteLimitKnown)
        <x-signal.ui.alert tone="warning" role="status">
            {{ __('The Analytics site allowance is unavailable for this plan. Contact a workspace administrator.') }}
        </x-signal.ui.alert>
    @elseif ($siteLimit === null)
        <x-signal.ui.alert tone="info" role="status">
            {{ __(':count Analytics sites · unlimited on :plan', ['count' => $siteCount, 'plan' => $plan->planName]) }}
        </x-signal.ui.alert>
    @else
        <x-signal.ui.alert :tone="$canCreateSite ? 'info' : 'warning'" role="status">
            {{ __(':used of :limit Analytics sites used on :plan', ['used' => $siteCount, 'limit' => $siteLimit, 'plan' => $plan->planName]) }}
        </x-signal.ui.alert>
    @endif

    <x-signal.ui.card as="form" class="space-y-6 p-6 sm:p-8" method="POST" :action="route('analytics.sites.store')">
        @csrf

        @if ($errors->any())
            <x-signal.ui.alert tone="danger" role="alert">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-signal.ui.alert>
        @endif

        <x-signal.ui.input-field
            name="name"
            :label="__('Website name')"
            :placeholder="__('Marketing site')"
            required
        />

        <x-signal.ui.input-field
            name="domain"
            :label="__('Primary domain')"
            :description="__('Use the hostname only. We will accept a URL too and normalize it.')"
            placeholder="example.com"
            required
        />

        <x-signal.ui.select-field
            name="timezone"
            :label="__('Reporting timezone')"
            :description="__('Daily reports use this timezone. Choose carefully before collection begins.')"
            required
        >
            @foreach ($timezones as $timezone => $label)
                <option value="{{ $timezone }}" @selected(old('timezone', 'UTC') === $timezone)>{{ $label }}</option>
            @endforeach
        </x-signal.ui.select-field>

        <div class="flex justify-end gap-3">
            <x-signal.ui.button :href="route('analytics.dashboard')">{{ __('Cancel') }}</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="primary" :disabled="! $canCreateSite">{{ __('Create website') }}</x-signal.ui.button>
        </div>
    </x-signal.ui.card>
</div>
@endsection
