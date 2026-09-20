<div data-command-output-content class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Retained command output') }}</p>
            <h3 class="mt-1 text-xl font-black text-primary">{{ __('Execution #:id', ['id' => $execution->id]) }}</h3>
            <p class="mt-1 text-sm text-secondary">{{ __('Output is loaded only when requested and remains available as a separate download.') }}</p>
        </div>
        <x-ui.button :href="route('servers.commands.output', ['server' => $server, 'execution' => $execution])" variant="secondary">
            {{ __('Download output') }}
        </x-ui.button>
    </div>

    <div class="rounded-xl border border-primary bg-secondary p-3">
        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Command') }}</p>
        <code class="block break-all text-xs text-primary">{{ $execution->command }}</code>
    </div>

    <pre class="max-h-[min(60vh,32rem)] overflow-auto whitespace-pre-wrap break-words rounded-xl border border-primary bg-slate-950 p-4 text-xs leading-6 text-slate-100" tabindex="0">{{ $execution->output }}</pre>
</div>
