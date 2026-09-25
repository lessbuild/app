@extends('analytics::layouts.app')

@section('content')
    <div class="mx-auto max-w-4xl space-y-8">
        <x-signal.ui.page-header
            :eyebrow="$workspace->name"
            :title="__('Data and privacy')"
            :description="__('Download the Analytics data owned by this workspace in a documented, streaming format.')"
        >
            <x-slot:actions>
                <x-signal.ui.button :href="route('analytics.workspaces.team', $workspace)" variant="secondary">{{ __('Team access') }}</x-signal.ui.button>
            </x-slot:actions>
        </x-signal.ui.page-header>

        <x-signal.ui.panel as="section" class="space-y-5 p-6" aria-labelledby="analytics-export-heading">
            <div>
                <p class="ui-eyebrow">{{ __('Workspace export') }}</p>
                <h2 id="analytics-export-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Export Analytics workspace data') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-muted">{{ __('The newline-delimited JSON export includes workspace membership, site settings, goals and their history, event batches, events, visits, conversions, daily report aggregates, connected-product annotations, usage periods, and report export metadata. Records stream from the Analytics database, so large workspaces do not need to fit in memory.') }}</p>
            </div>

            <x-signal.ui.alert tone="info" role="note">
                {{ __('Visitor and session values are pseudonymous identifiers included to preserve Analytics relationships. Site verification tokens, report download tokens, generated file paths, and ingestion failure details are excluded. Collection removes URL query strings and only accepts a validated event name as a custom property.') }}
            </x-signal.ui.alert>

            <div class="flex flex-wrap items-center gap-3">
                <x-signal.ui.button :href="route('analytics.workspaces.data.export', $workspace)" variant="primary">
                    {{ __('Download workspace export') }}
                </x-signal.ui.button>
                <p class="text-xs text-muted">{{ __('Available to workspace owners and administrators. Site records include authorized archived history while respecting current project access. Downloading does not change workspace data.') }}</p>
            </div>
        </x-signal.ui.panel>
    </div>
@endsection
