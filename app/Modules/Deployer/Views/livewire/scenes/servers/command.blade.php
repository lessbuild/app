<div @if ($shouldPoll) wire:poll.2s @endif>
    @if ($open)
        <x-signal.overlays.dialog-shell
            id="server-command-dialog"
            :open="true"
            class="ui-command-dialog"
            data-livewire-dialog
            role="dialog"
            aria-modal="true"
            aria-labelledby="server-command-dialog-title"
        >
                <form wire:submit.prevent="run">
                    <header data-modal-header class="flex items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
                        <div class="min-w-0">
                            <p class="ui-eyebrow">{{ __('Remote operations') }}</p>
                            <h2 id="server-command-dialog-title" tabindex="-1" class="mt-2 text-xl font-extrabold text-ink">{{ __('Run command on :server', ['server' => $model->name]) }}</h2>
                            <p class="mt-2 text-sm text-muted">{{ __('The command runs as root and stops after the configured SSH timeout.') }}</p>
                        </div>
                        <x-signal.ui.icon-button :label="__('Close command dialog')" type="button" wire:click="close" data-livewire-dialog-close class="ui-icon-btn text-xl leading-none">
                            <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
                        </x-signal.ui.icon-button>
                    </header>

                    <div data-modal-body class="px-5 py-5 sm:px-6">

                        <label for="server-command-input" class="sr-only">{{ __('Command') }}</label>
                        <x-signal.ui.input
                            id="server-command-input"
                            wire:model.defer="command"
                            type="text"
                            class="ui-input font-mono"
                            placeholder="{{ __('Example: uptime') }}"
                            autocomplete="off"
                            autofocus :restore="false" />
                        @error('command')
                            <x-signal.ui.alert tone="danger" class="mt-3">{{ $message }}</x-signal.ui.alert>
                        @enderror
                        @error('cancel')
                            <x-signal.ui.alert tone="danger" class="mt-3">{{ $message }}</x-signal.ui.alert>
                        @enderror

                        <div class="mt-6 flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="ui-eyebrow">{{ __('Recent commands') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('Output is retained only for the configured history window.') }}</p>
                            </div>
                            <x-signal.ui.button :href="route('servers.commands.index', $model)" variant="ghost" class="px-0">
                                {{ __('View full history') }}
                            </x-signal.ui.button>
                        </div>

                        <div class="mt-3 max-h-96 space-y-3 overflow-y-auto pr-1">
                            @forelse ($executions as $execution)
                                @php($statusTone = match ($execution->status) {
                                    \App\Modules\Deployer\Models\ServerCommandExecution::STATUS_SUCCEEDED => 'success',
                                    \App\Modules\Deployer\Models\ServerCommandExecution::STATUS_FAILED => 'danger',
                                    \App\Modules\Deployer\Models\ServerCommandExecution::STATUS_CANCELED => 'warning',
                                    default => 'accent',
                                })
                                <article class="ui-card ui-card--muted p-4" wire:key="server-command-{{ $execution->id }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <code class="min-w-0 flex-1 break-all text-xs text-ink">{{ $execution->command }}</code>
                                        <x-signal.ui.badge :tone="$statusTone">{{ $execution->status }}</x-signal.ui.badge>
                                    </div>
                                    @if ($execution->rerun_from_execution_id)
                                        <p class="mt-2 text-xs text-muted">{{ __('Rerun of command #:id', ['id' => $execution->rerun_from_execution_id]) }}</p>
                                    @endif
                                    @if ($execution->output !== null)
                                        <pre class="ui-console ui-console-output mt-3 max-h-56 whitespace-pre-wrap p-3">{{ $execution->output }}</pre>
                                    @elseif (in_array($execution->status, \App\Modules\Deployer\Models\ServerCommandExecution::ACTIVE_STATUSES, true))
                                        <p class="mt-2 text-xs text-muted">{{ __('Waiting for command output…') }}</p>
                                    @endif
                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                        <p class="text-xs text-muted">
                                            {{ $execution->created_at->diffForHumans() }}
                                            @if ($execution->exit_code !== null)
                                                · {{ __('exit :code', ['code' => $execution->exit_code]) }}
                                            @endif
                                        </p>
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($execution->output !== null)
                                                <x-signal.ui.button :href="route('servers.commands.output', ['server' => $model, 'execution' => $execution])" variant="ghost" class="px-2 py-1 text-xs">
                                                    {{ __('Download') }}
                                                </x-signal.ui.button>
                                            @endif
                                            @if ($execution->status === \App\Modules\Deployer\Models\ServerCommandExecution::STATUS_QUEUED)
                                                <x-signal.ui.button
                                                    type="button"
                                                    variant="danger"
                                                    wire:click="cancel({{ $execution->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="cancel({{ $execution->id }})"
                                                    class="px-2 py-1 text-xs"
                                                >
                                                    {{ __('Cancel') }}
                                                </x-signal.ui.button>
                                            @endif
                                            @if ($model->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_ACTIVE
                                                && in_array($execution->status, \App\Modules\Deployer\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                                                <x-signal.ui.button
                                                    type="button"
                                                    variant="secondary"
                                                    wire:click="rerun({{ $execution->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="rerun({{ $execution->id }})"
                                                    class="px-2 py-1 text-xs"
                                                >
                                                    {{ __('Run again') }}
                                                </x-signal.ui.button>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @empty
                                <x-signal.ui.empty-state :title="__('No commands have been run on this server yet.')" />
                            @endforelse
                        </div>
                    </div>

                    <footer data-modal-footer class="flex flex-wrap-reverse justify-end gap-2 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
                        <x-signal.ui.button type="button" variant="ghost" wire:click="close">{{ __('Close') }}</x-signal.ui.button>
                        <x-signal.ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="run">
                            <span wire:loading.remove wire:target="run">{{ __('Run command') }}</span>
                            <span wire:loading wire:target="run">{{ __('Running…') }}</span>
                        </x-signal.ui.button>
                    </footer>
                </form>
        </x-signal.overlays.dialog-shell>
    @endif
</div>
