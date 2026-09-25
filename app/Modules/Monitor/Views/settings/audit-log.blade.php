@extends('monitor::layouts.app')

@section('title', 'Audit log')
@section('breadcrumb', 'Audit log')

@section('content')
    <div class="space-y-6">
        <x-monitor::ui.page-header eyebrow="Security & change history" eyebrow-icon="activity" title="Know who changed what." description="Review access, credential, alert, and monitor changes across this workspace. Sensitive values are redacted before they are stored.">
            @if($auditLogEnabled)
                <x-slot:actions><x-monitor::ui.badge tone="green">Recording changes</x-monitor::ui.badge></x-slot:actions>
            @endif
        </x-monitor::ui.page-header>

        @unless($auditLogEnabled)
            <x-signal.ui.panel as="section" class="overflow-hidden p-6 sm:p-8">
                <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                    <div class="max-w-2xl">
                        <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-primary"><x-monitor::icon name="shield" class="h-4 w-4" />Paid team control</div>
                        <h2 class="mt-3 text-xl font-bold sm:text-2xl">Make every configuration change accountable.</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">The audit log is included with Pro and above. It records workspace access, token lifecycle, alert routing, and monitor changes with actor, time, IP, and redacted context.</p>
                    </div>
                    @can('billing', $workspace)
                        <x-monitor::ui.button :href="route('monitor.settings.billing')" class="shrink-0">View plans <x-monitor::icon name="arrow-up-right" class="h-3.5 w-3.5" /></x-monitor::ui.button>
                    @endcan
                </div>
            </x-signal.ui.panel>
        @else
            <x-signal.ui.panel as="section" class="p-5">
                <form method="GET" action="{{ route('monitor.settings.audit-log') }}" class="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                    <x-monitor::ui.select name="action" label="Change type" :value="$selectedAction" :options="$actions" placeholder="All changes" />
                    <x-monitor::ui.select name="actor_id" label="Actor" :value="$selectedActor" :options="$actors->mapWithKeys(fn ($actor) => [$actor->id => $actor->name])->all()" placeholder="Everyone" />
                    <div class="flex gap-2"><x-monitor::ui.button type="submit">Filter</x-monitor::ui.button><x-monitor::ui.button :href="route('monitor.settings.audit-log')" variant="secondary">Reset</x-monitor::ui.button></div>
                </form>
            </x-signal.ui.panel>

            <x-signal.ui.panel as="section" class="overflow-hidden">
                <div class="border-b border-line px-5 py-4 dark:border-line"><h2 class="font-bold">Workspace activity</h2><p class="mt-1 text-xs text-muted dark:text-subtle">Configuration changes are retained with the workspace and never include raw secrets.</p></div>
                <div class="divide-y divide-line dark:divide-line">
                    @forelse($auditLogs as $log)
                        <details class="group px-5 py-4">
                            <summary class="flex cursor-pointer list-none flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-control bg-primary-soft text-primary dark:bg-primary-soft dark:text-primary"><x-monitor::icon name="activity" class="h-4 w-4" /></span>
                                    <div class="min-w-0"><p class="truncate text-sm font-bold">{{ $log->label() }}</p><p class="mt-1 truncate text-xs text-muted dark:text-subtle">{{ $log->subjectLabel() }} · {{ $log->actor?->name ?? 'System' }}</p></div>
                                </div>
                                <div class="flex items-center gap-3 pl-11 text-xs text-subtle sm:shrink-0 sm:pl-0"><time datetime="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->utc()->format('Y-m-d H:i:s') }} UTC</time><x-monitor::icon name="chevron-down" class="h-4 w-4 transition group-open:rotate-180" /></div>
                            </summary>
                            <div class="ml-11 mt-4 grid gap-4 rounded-control bg-surface-muted p-4 text-xs dark:bg-surface-muted sm:grid-cols-2">
                                <div><p class="font-bold text-ink dark:text-ink">Request context</p><dl class="mt-2 space-y-1.5 text-muted dark:text-subtle"><div class="flex justify-between gap-3"><dt>IP address</dt><dd class="font-mono text-right">{{ $log->ip_address ?? 'Not available' }}</dd></div><div class="flex justify-between gap-3"><dt>Actor</dt><dd class="text-right">{{ $log->actor?->email ?? 'System action' }}</dd></div></dl></div>
                                <div><p class="font-bold text-ink dark:text-ink">Recorded details</p><dl class="mt-2 space-y-1.5 text-muted dark:text-subtle">@foreach($log->metadata ?? [] as $key => $value)<div class="flex justify-between gap-3"><dt>{{ ucwords(str_replace('_', ' ', (string) $key)) }}</dt><dd class="max-w-[65%] break-words text-right">{{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value) }}</dd></div>@endforeach</dl></div>
                            </div>
                        </details>
                    @empty
                        <div class="px-5 py-12 text-center"><span class="mx-auto flex h-10 w-10 items-center justify-center rounded-control bg-surface-muted text-subtle dark:bg-surface-muted"><x-monitor::icon name="activity" class="h-5 w-5" /></span><h3 class="mt-4 text-sm font-bold">No changes match these filters.</h3><p class="mt-1 text-xs text-muted dark:text-subtle">New workspace configuration activity will appear here.</p></div>
                    @endforelse
                </div>
                @if($auditLogs?->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $auditLogs->links() }}</div>@endif
            </x-signal.ui.panel>
        @endunless
    </div>
@endsection
