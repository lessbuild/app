@extends('monitor::layouts.app')

@section('title', 'Notifications')
@section('breadcrumb', 'Notifications')

@section('content')
    <div class="space-y-6">
        <x-monitor::ui.page-header eyebrow="WORKSPACE SETTINGS" title="Stay close to the signal." :description="'Choose whether '.config('app.name').' sends you a daily summary of new, resolved, and still-open issues.'" />

        @unless($digestAvailable)
            <x-signal.ui.alert as="section" tone="info" class="border-primary/30 bg-primary-soft block p-5">
                <div class="flex gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-primary-soft text-primary dark:bg-primary-soft dark:text-primary"><x-monitor::icon name="shield" class="h-4 w-4" /></span>
                    <div><h2 class="text-sm font-bold text-primary dark:text-primary">Issue digests are included with your plan.</h2><p class="mt-1 text-xs leading-5 text-primary dark:text-primary">Upgrade your workspace plan to activate scheduled issue summaries.</p></div>
                </div>
            </x-signal.ui.alert>
        @endunless

        @unless(auth()->user()->hasVerifiedEmail())
            <x-signal.ui.alert as="section" tone="warning" class="block p-5">
                <div class="flex gap-3"><x-monitor::icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-warning dark:text-warning" /><p class="text-xs leading-5 text-warning dark:text-warning">Verify {{ auth()->user()->email }} before {{ config('app.name') }} can deliver email notifications.</p></div>
            </x-signal.ui.alert>
        @endunless

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(300px,0.9fr)]">
            <x-signal.ui.card as="div" class="p-6">
                <div class="flex items-start justify-between gap-4"><div><h2 class="font-bold">Daily issue digest</h2><p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">One email at 08:00 UTC when there is issue activity or an open issue to investigate.</p></div><x-monitor::ui.badge :tone="$digestEnabled && $digestAvailable ? 'green' : 'slate'">{{ $digestEnabled && $digestAvailable ? 'Enabled' : 'Paused' }}</x-monitor::ui.badge></div>
                <form method="POST" action="{{ route('monitor.settings.notifications.update') }}" class="mt-6 flex flex-col gap-5 border-t border-line pt-5 dark:border-line sm:flex-row sm:items-center sm:justify-between">
                    @csrf @method('PATCH')
                    <x-signal.ui.input type="hidden" name="enabled" value="0" :restore="false" />
                    <x-monitor::ui.choice id="digest-enabled" name="enabled" :checked="old('enabled', $digestEnabled)" label="Send me the daily digest" :description="$isWorkspaceOwner && $preference === null ? 'Workspace owners receive this by default.' : 'You can pause this any time.'" />
                    <x-monitor::ui.button class="shrink-0">Save preference</x-monitor::ui.button>
                </form>
            </x-signal.ui.card>
            <x-signal.ui.card as="div" class="p-6">
                <div class="flex items-center gap-2 text-xs font-bold text-primary dark:text-primary"><x-monitor::icon name="inbox" class="h-4 w-4" />Delivery behavior</div>
                <ul class="mt-5 space-y-4 text-xs leading-5 text-muted dark:text-muted"><li class="flex gap-3"><x-monitor::icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-success" /><span>One delivery record is created for each workspace, recipient, and UTC period.</span></li><li class="flex gap-3"><x-monitor::icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-success" /><span>Rerunning a command cannot resend a completed period.</span></li><li class="flex gap-3"><x-monitor::icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-success" /><span>Issue titles and locations are redacted before the email is prepared.</span></li></ul>
            </x-signal.ui.card>
        </section>

        <x-signal.ui.panel as="section" class="overflow-hidden">
            <div class="border-b border-line px-6 py-4 dark:border-line"><h2 class="font-bold">Your delivery history</h2><p class="mt-1 text-xs text-muted dark:text-subtle">The latest 20 issue digest attempts for {{ auth()->user()->email }}.</p></div>
            <div class="divide-y divide-line dark:divide-line">
                @forelse($deliveries as $delivery)
                    @php($tone = match($delivery->status) { 'sent' => 'green', 'failed' => 'red', default => 'amber' })
                    <div class="flex flex-col justify-between gap-4 px-6 py-4 sm:flex-row sm:items-center"><div><div class="flex flex-wrap items-center gap-2"><p class="text-sm font-semibold">{{ $delivery->period_start->utc()->format('Y-m-d H:i') }} → {{ $delivery->period_end->utc()->format('Y-m-d H:i') }} UTC</p><x-monitor::ui.badge :tone="$tone">{{ ucfirst($delivery->status) }}</x-monitor::ui.badge></div><p class="mt-1 text-xs text-muted dark:text-subtle">
                        @if($delivery->summary_available)
                            {{ $delivery->new_count }} new · {{ $delivery->resolved_count }} resolved · {{ $delivery->open_count }} open{{ $delivery->critical_open_count > 0 ? ' · '.$delivery->critical_open_count.' critical' : '' }}
                        @else
                            Summary unavailable under your current project access.
                        @endif
                    </p></div><time class="text-xs text-subtle" datetime="{{ ($delivery->sent_at ?? $delivery->failed_at ?? $delivery->created_at)->toIso8601String() }}">{{ ($delivery->sent_at ?? $delivery->failed_at ?? $delivery->created_at)->diffForHumans() }}</time></div>
                @empty
                    <div class="px-6 py-12 text-center"><span class="mx-auto flex h-10 w-10 items-center justify-center rounded-control bg-surface-muted text-subtle dark:bg-surface-muted"><x-monitor::icon name="inbox" class="h-5 w-5" /></span><h3 class="mt-4 text-sm font-bold">No digest deliveries yet.</h3><p class="mt-1 text-xs text-muted dark:text-subtle">Once an issue digest is sent, its delivery result will appear here.</p></div>
                @endforelse
            </div>
        </x-signal.ui.panel>
    </div>
@endsection
