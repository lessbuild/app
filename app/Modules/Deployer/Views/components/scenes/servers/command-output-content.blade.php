<div data-command-output-content class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow">{{ __('Retained command output') }}</p>
            <h3 class="mt-1 text-xl font-extrabold text-ink">{{ __('Execution #:id', ['id' => $execution->id]) }}</h3>
            <p class="mt-1 text-sm text-muted">{{ __('Output is loaded only when requested and remains available as a separate download.') }}</p>
        </div>
        <x-ui.button :href="route('servers.commands.output', ['server' => $server, 'execution' => $execution])" variant="secondary" class="ui-btn-sm">
            {{ __('Download output') }}
        </x-ui.button>
    </div>

    <div class="ui-panel p-3">
        <p class="ui-eyebrow text-[0.65rem]">{{ __('Command') }}</p>
        <code class="mt-2 block break-all font-mono text-xs text-ink">{{ $execution->command }}</code>
    </div>

    <pre class="ui-console ui-console-output max-h-[min(60vh,32rem)] whitespace-pre-wrap break-words p-4 font-mono text-xs leading-6" tabindex="0">{{ $execution->output }}</pre>
</div>
