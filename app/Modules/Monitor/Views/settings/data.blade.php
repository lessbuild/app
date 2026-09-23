@extends('monitor::layouts.app')

@section('title', 'Data & privacy')
@section('breadcrumb', 'Data & privacy')

@section('content')
    <div class="space-y-6">
        <x-monitor::ui.page-header eyebrow="Workspace settings" eyebrow-icon="shield" title="Keep your data portable." description="Download a line-oriented export of this workspace for audits, migrations, backups, or privacy requests. Exports are streamed so large telemetry workspaces do not need to fit in memory.">
            <x-slot:actions>
                @can('update', $workspace)
                    @if(auth()->user()->hasVerifiedEmail())
                        <x-monitor::ui.button :href="route('monitor.settings.data.export')"><x-monitor::icon name="arrow-down" class="h-3.5 w-3.5" />Download export</x-monitor::ui.button>
                    @else
                        <x-monitor::ui.button :href="route('monitor.verification.notice')" class="gap-2 border-warning text-warning hover:bg-warning-soft dark:border-warning dark:text-warning dark:hover:bg-warning-soft" variant="secondary"><x-monitor::icon name="inbox" class="h-3.5 w-3.5" />Verify email to export</x-monitor::ui.button>
                    @endif
                @endcan
            </x-slot:actions>
        </x-monitor::ui.page-header>

        <div class="grid gap-5 lg:grid-cols-2">
            <x-monitor::ui.card>
                <div class="flex items-center gap-3"><x-monitor::ui.badge tone="violet">Included</x-monitor::ui.badge><h2 class="font-bold">Operational records</h2></div>
                <ul class="mt-5 space-y-3 text-sm leading-6 text-muted dark:text-muted">
                    <li class="flex gap-3"><x-monitor::icon name="check" class="mt-1 h-4 w-4 shrink-0 text-success" />Workspace members, roles, and profile details.</li>
                    <li class="flex gap-3"><x-monitor::icon name="check" class="mt-1 h-4 w-4 shrink-0 text-success" />Applications, environments, lifecycle state, and usage counters.</li>
                    <li class="flex gap-3"><x-monitor::icon name="check" class="mt-1 h-4 w-4 shrink-0 text-success" />Issues, incidents, audit entries, and retained telemetry.</li>
                    <li class="flex gap-3"><x-monitor::icon name="check" class="mt-1 h-4 w-4 shrink-0 text-success" />Telemetry attributes and payloads exactly as stored after {{ config('app.name') }} redaction.</li>
                </ul>
            </x-monitor::ui.card>

            <x-monitor::ui.card>
                <div class="flex items-center gap-3"><x-monitor::ui.badge tone="green">Excluded</x-monitor::ui.badge><h2 class="font-bold">Credentials stay protected</h2></div>
                <ul class="mt-5 space-y-3 text-sm leading-6 text-muted dark:text-muted">
                    <li class="flex gap-3"><x-monitor::icon name="shield" class="mt-1 h-4 w-4 shrink-0 text-primary" />Environment, heartbeat, and queue token secrets.</li>
                    <li class="flex gap-3"><x-monitor::icon name="shield" class="mt-1 h-4 w-4 shrink-0 text-primary" />Webhook endpoints, signing keys, and provider routing keys.</li>
                    <li class="flex gap-3"><x-monitor::icon name="shield" class="mt-1 h-4 w-4 shrink-0 text-primary" />Encrypted pending ingestion payloads and account passwords.</li>
                </ul>
            </x-monitor::ui.card>
        </div>

        <section class="ui-panel p-6">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="font-bold">NDJSON export format</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-muted dark:text-subtle">Each line is an independent JSON record with a <code class="rounded bg-surface-muted px-1 py-0.5 text-xs dark:bg-surface-muted">type</code> and <code class="rounded bg-surface-muted px-1 py-0.5 text-xs dark:bg-surface-muted">data</code> property. It can be processed incrementally by shell tools, data warehouses, or your own migration script.</p>
                </div>
                @can('update', $workspace)
                    @if(auth()->user()->hasVerifiedEmail())
                        <a href="{{ route('monitor.settings.data.export') }}" class="shrink-0 text-xs font-bold text-primary hover:underline dark:text-primary">Start another export →</a>
                    @endif
                @endcan
            </div>
            <p class="ui-card shadow-none mt-5 bg-surface-muted p-4 text-xs leading-5 text-muted dark:bg-surface-muted dark:text-muted">Exports are generated on demand and are not retained by {{ config('app.name') }}. Treat the downloaded file as sensitive because it contains workspace members and telemetry context.</p>
        </section>
    </div>
@endsection
