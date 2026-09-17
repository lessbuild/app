<div @if ($shouldPoll) wire:poll.2s @endif>
    @if ($open)
        <div class="relative z-10" role="dialog" aria-modal="true" aria-labelledby="server-command-dialog-title">
            <button type="button" wire:click="close" class="fixed inset-0 bg-slate-950/70" aria-label="{{ __('Close command dialog') }}"></button>
            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center">
                    <div class="ui-card relative w-full max-w-2xl overflow-hidden text-left shadow-xl">
                        <form wire:submit.prevent="run">
                            <div class="px-5 py-5 sm:px-6">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Remote operations') }}</p>
                                        <h2 id="server-command-dialog-title" class="mt-1 text-xl font-black text-primary">{{ __('Run command on :server', ['server' => $model->name]) }}</h2>
                                        <p class="mt-1 text-sm text-secondary">{{ __('The command runs as root and stops after the configured SSH timeout.') }}</p>
                                    </div>
                                    <button type="button" wire:click="close" class="rounded-lg p-2 text-xl leading-none text-secondary hover:bg-secondary hover:text-primary" aria-label="{{ __('Close command dialog') }}">
                                        <span aria-hidden="true">×</span>
                                    </button>
                                </div>

                                <label for="server-command-input" class="sr-only">{{ __('Command') }}</label>
                                <input
                                    id="server-command-input"
                                    wire:model.defer="command"
                                    type="text"
                                    class="input secondary mt-5 w-full rounded-lg font-mono"
                                    placeholder="{{ __('Example: uptime') }}"
                                    autocomplete="off"
                                    autofocus
                                >
                                @error('command')
                                    <x-ui.alert tone="danger" class="mt-3">{{ $message }}</x-ui.alert>
                                @enderror
                                @error('cancel')
                                    <x-ui.alert tone="danger" class="mt-3">{{ $message }}</x-ui.alert>
                                @enderror

                                <div class="mt-6 flex flex-wrap items-end justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Recent commands') }}</p>
                                        <p class="mt-1 text-sm text-secondary">{{ __('Output is retained only for the configured history window.') }}</p>
                                    </div>
                                    <x-ui.button :href="route('servers.commands.index', $model)" variant="ghost" class="px-0">
                                        {{ __('View full history') }}
                                    </x-ui.button>
                                </div>

                                <div class="mt-3 max-h-96 space-y-3 overflow-y-auto pr-1">
                                    @forelse ($executions as $execution)
                                        @php($statusTone = match ($execution->status) {
                                            \App\Models\ServerCommandExecution::STATUS_SUCCEEDED => 'success',
                                            \App\Models\ServerCommandExecution::STATUS_FAILED => 'danger',
                                            \App\Models\ServerCommandExecution::STATUS_CANCELED => 'warning',
                                            default => 'accent',
                                        })
                                        <article class="rounded-xl border border-primary bg-secondary p-4" wire:key="server-command-{{ $execution->id }}">
                                            <div class="flex flex-wrap items-start justify-between gap-3">
                                                <code class="min-w-0 flex-1 break-all text-xs text-primary">{{ $execution->command }}</code>
                                                <x-ui.badge :tone="$statusTone">{{ $execution->status }}</x-ui.badge>
                                            </div>
                                            @if ($execution->rerun_from_execution_id)
                                                <p class="mt-2 text-xs text-secondary">{{ __('Rerun of command #:id', ['id' => $execution->rerun_from_execution_id]) }}</p>
                                            @endif
                                            @if ($execution->output !== null)
                                                <pre class="mt-3 max-h-56 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-950 p-3 text-xs leading-5 text-slate-100">{{ $execution->output }}</pre>
                                            @elseif (in_array($execution->status, \App\Models\ServerCommandExecution::ACTIVE_STATUSES, true))
                                                <p class="mt-2 text-xs text-secondary">{{ __('Waiting for command output…') }}</p>
                                            @endif
                                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                                <p class="text-xs text-secondary">
                                                    {{ $execution->created_at->diffForHumans() }}
                                                    @if ($execution->exit_code !== null)
                                                        · {{ __('exit :code', ['code' => $execution->exit_code]) }}
                                                    @endif
                                                </p>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    @if ($execution->output !== null)
                                                        <x-ui.button :href="route('servers.commands.output', ['server' => $model, 'execution' => $execution])" variant="ghost" class="px-2 py-1 text-xs">
                                                            {{ __('Download') }}
                                                        </x-ui.button>
                                                    @endif
                                                    @if ($execution->status === \App\Models\ServerCommandExecution::STATUS_QUEUED)
                                                        <x-ui.button
                                                            type="button"
                                                            variant="danger"
                                                            wire:click="cancel({{ $execution->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="cancel({{ $execution->id }})"
                                                            class="px-2 py-1 text-xs"
                                                        >
                                                            {{ __('Cancel') }}
                                                        </x-ui.button>
                                                    @endif
                                                    @if ($model->provisioning_status === \App\Models\Server::STATUS_ACTIVE
                                                        && in_array($execution->status, \App\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                                                        <x-ui.button
                                                            type="button"
                                                            variant="secondary"
                                                            wire:click="rerun({{ $execution->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="rerun({{ $execution->id }})"
                                                            class="px-2 py-1 text-xs"
                                                        >
                                                            {{ __('Run again') }}
                                                        </x-ui.button>
                                                    @endif
                                                </div>
                                            </div>
                                        </article>
                                    @empty
                                        <x-ui.empty-state :title="__('No commands have been run on this server yet.')" />
                                    @endforelse
                                </div>
                            </div>
                            <div class="flex flex-wrap-reverse justify-end gap-2 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
                                <x-ui.button type="button" variant="ghost" wire:click="close">{{ __('Close') }}</x-ui.button>
                                <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="run">
                                    <span wire:loading.remove wire:target="run">{{ __('Run command') }}</span>
                                    <span wire:loading wire:target="run">{{ __('Running…') }}</span>
                                </x-ui.button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
