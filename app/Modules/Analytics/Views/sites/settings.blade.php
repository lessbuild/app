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
    $domains = implode("\n", $site->domains ?? []);
    $excludedPaths = implode("\n", $site->excluded_paths ?? []);
@endphp

<div class="mx-auto max-w-4xl space-y-8">
    <x-signal.ui.page-header
        :eyebrow="__('Website settings')"
        :title="$site->name"
        :description="__('Control collection, domains, privacy exclusions, and the installation snippet.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('analytics.dashboard')">{{ __('Back to overview') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @if ($errors->any())
        <x-signal.ui.alert tone="danger" role="alert">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-signal.ui.alert>
    @endif

    <x-signal.ui.card as="form" class="space-y-6 p-6 sm:p-8" method="POST" :action="route('analytics.sites.settings.update', $site)">
        @csrf
        @method('PUT')

        <x-signal.ui.input-field
            name="name"
            :label="__('Website name')"
            :value="$site->name"
            required
        />

        <x-signal.ui.textarea-field
            name="domains"
            :label="__('Allowed domains')"
            :value="$domains"
            :description="__('One exact hostname per line. Origins are checked against this list before events are accepted.')"
            class="[&_textarea]:min-h-24"
            required
        />

        <x-signal.ui.select-field
            name="timezone"
            :label="__('Reporting timezone')"
            :description="__('The timezone is frozen after the first event to keep local-day reports stable.')"
            required
        >
            @foreach ($timezones as $timezone => $label)
                <option value="{{ $timezone }}" @selected(old('timezone', $site->timezone) === $timezone)>{{ $label }}</option>
            @endforeach
        </x-signal.ui.select-field>

        <x-signal.ui.textarea-field
            name="excluded_paths"
            :label="__('Excluded paths')"
            :value="$excludedPaths"
            :description="__('One path or wildcard per line. Excluded paths are dropped before durable storage.')"
            placeholder="/admin/* or /account"
            class="[&_textarea]:min-h-28"
        />

        <fieldset class="grid gap-3 border-t border-line pt-6 sm:grid-cols-2">
            <legend class="sr-only">{{ __('Collection controls') }}</legend>
            <x-signal.ui.choice
                id="collection_enabled"
                name="collection_enabled"
                :label="__('Accept new collection requests')"
                :description="__('Turn this off to stop accepting new tracker events.')"
                :checked="$site->collection_enabled"
                unchecked-value="0"
                card
            />
            <x-signal.ui.choice
                id="collection_paused"
                name="collection_paused"
                :label="__('Pause collection temporarily')"
                :description="__('Pause event collection without changing domain verification.')"
                :checked="$site->collection_paused_at !== null"
                unchecked-value="0"
                card
            />
        </fieldset>

        <div class="flex justify-end">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Save settings') }}</x-signal.ui.button>
        </div>
    </x-signal.ui.card>

    <x-signal.ui.card as="section" class="p-6">
        <p class="ui-eyebrow">{{ __('Installation') }}</p>
        <h2 class="mt-2 text-lg font-extrabold text-ink">{{ __('Tracker snippet') }}</h2>
        <pre class="mt-4 overflow-x-auto rounded-card bg-zinc-950 p-4 text-xs leading-6 text-zinc-200"><code>&lt;script defer src="{{ url('/tracker/v1.js') }}" data-site="{{ $site->public_id }}"&gt;&lt;/script&gt;</code></pre>
        <p class="mt-3 text-xs leading-5 text-muted">
            {{ __('Collection status: :status · Detail retention: :days days.', ['status' => $site->isCollectionAvailable() ? __('active') : __('not accepting events'), 'days' => config('analytics.event_retention_days')]) }}
        </p>
    </x-signal.ui.card>

    <x-signal.ui.card as="section" class="flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
        <div>
            <p class="ui-eyebrow">{{ __('Workspace access') }}</p>
            <h2 class="mt-2 text-lg font-extrabold text-ink">{{ __('Invite your team') }}</h2>
            <p class="mt-2 text-sm text-muted">{{ __('Manage owner, admin, and viewer access for :workspace.', ['workspace' => $site->workspace->name]) }}</p>
        </div>
        <x-signal.ui.button :href="route('analytics.workspaces.team', $site->workspace)">{{ __('Open team settings') }}</x-signal.ui.button>
    </x-signal.ui.card>

    <x-signal.ui.card as="section" class="border-danger/20 p-6">
        <p class="ui-eyebrow text-danger">{{ __('Danger zone') }}</p>
        <h2 class="mt-2 text-lg font-extrabold text-ink">{{ __('Delete this website') }}</h2>
        <p class="mt-2 text-sm leading-6 text-muted">{{ __('Collection stops immediately and stored events, visits, goals, and batches are removed.') }}</p>
        <form class="mt-5" method="POST" action="{{ route('analytics.sites.destroy', $site) }}">
            @csrf
            <x-signal.ui.button variant="danger" type="submit">{{ __('Delete website') }}</x-signal.ui.button>
        </form>
    </x-signal.ui.card>
</div>
@endsection
