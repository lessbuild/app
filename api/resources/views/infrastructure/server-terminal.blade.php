@php($project = $overview->project)
@push('head')
    @vite('resources/js/terminal.js')
@endpush

<x-signal.layouts.project :overview="$overview" :title="__('Terminal')" :description="__('A root shell on :server. It closes after :idle idle minutes, or :limit minutes at most. Opening and closing it are recorded in the audit log.', ['server' => $server->label(), 'idle' => config('infrastructure.terminal.idle_minutes'), 'limit' => config('infrastructure.terminal.session_minutes')])">
    @if (! $ownBrowser)
        <x-signal.ui.alert tone="warning">{{ __('This terminal was opened in another browser or tab session. Open a new one from the server page.') }}</x-signal.ui.alert>
    @elseif (! $terminal->isActive())
        <x-signal.ui.alert tone="info">{{ __('This terminal has closed (:reason).', ['reason' => $terminal->close_reason ?? $terminal->status]) }}</x-signal.ui.alert>
    @else
        <x-signal.ui.card class="grid gap-3 overflow-hidden p-3">
            <div
                data-terminal
                data-columns="{{ $terminal->columns }}"
                data-rows="{{ $terminal->rows }}"
                data-input-url="{{ route('infrastructure.servers.terminal.input', [$project, $server->id, $terminal->id]) }}"
                data-output-url="{{ route('infrastructure.servers.terminal.output', [$project, $server->id, $terminal->id]) }}"
                data-connected-text="{{ __('Connected') }}"
                data-closed-text="{{ __('Closed') }}"
                class="grid gap-2"
            >
                <p class="text-xs text-muted" data-terminal-status role="status" aria-live="polite">{{ __('Connecting…') }}</p>
                <div class="overflow-x-auto rounded-control bg-[#0b1020] p-2"><div data-terminal-screen></div></div>
            </div>
        </x-signal.ui.card>
    @endif
    <div class="flex flex-wrap gap-3">
        @if ($terminal->isActive())
            <form method="POST" action="{{ route('infrastructure.servers.terminal.destroy', [$project, $server->id, $terminal->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="secondary">{{ __('Close terminal') }}</x-signal.ui.button></form>
        @endif
        <x-signal.ui.button :href="route('infrastructure.servers.show', [$project, $server->id])" variant="quiet">{{ __('Back to the server') }}</x-signal.ui.button>
    </div>
</x-signal.layouts.project>
