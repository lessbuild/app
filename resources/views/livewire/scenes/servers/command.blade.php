<div @if ($shouldPoll) wire:poll.2s @endif>
    @if ($open)
        <div class="relative z-10" role="dialog" aria-modal="true">
            <button type="button" wire:click="close" class="fixed inset-0 bg-secondary opacity-70" aria-label="{{ __('Close command dialog') }}"></button>
            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center">
                    <div class="relative w-full max-w-2xl rounded-lg border border-primary bg-primary text-left shadow-xl">
                        <form wire:submit.prevent="run">
                            <div class="px-6 py-5">
                                <h3 class="text-lg font-medium text-primary">{{ __('Run command on :server', ['server' => $model->name]) }}</h3>
                                <p class="mt-1 text-sm text-secondary">{{ __('The command runs as root and stops after the configured SSH timeout.') }}</p>
                                <input
                                    wire:model.defer="command"
                                    type="text"
                                    class="input secondary mt-4 w-full rounded font-mono"
                                    placeholder="{{ __('Example: uptime') }}"
                                    autocomplete="off"
                                    autofocus
                                >
                                @error('command') <p class="mt-2 text-sm text-red-500">{{ $message }}</p> @enderror

                                <div class="mt-4 flex items-center justify-between gap-3">
                                    <p class="text-xs font-semibold uppercase text-secondary">{{ __('Recent commands') }}</p>
                                    <a href="{{ route('servers.commands.index', $model) }}" class="text-xs font-medium text-ternary hover:underline">
                                        {{ __('View full history') }}
                                    </a>
                                </div>
                                @error('cancel') <p class="mt-2 text-sm text-red-500">{{ $message }}</p> @enderror

                                <div class="mt-3 max-h-96 space-y-3 overflow-y-auto">
                                    @forelse ($executions as $execution)
                                        <div class="rounded border border-primary bg-secondary p-3" wire:key="server-command-{{ $execution->id }}">
                                            <div class="flex items-center justify-between gap-3">
                                                <code class="min-w-0 break-all text-xs text-primary">{{ $execution->command }}</code>
                                                <span class="shrink-0 text-xs font-semibold uppercase text-secondary">{{ $execution->status }}</span>
                                            </div>
                                            @if ($execution->rerun_from_execution_id)
                                                <p class="mt-1 text-xs text-secondary">{{ __('Rerun of command #:id', ['id' => $execution->rerun_from_execution_id]) }}</p>
                                            @endif
                                            @if ($execution->output !== null)
                                                <pre class="mt-3 max-h-56 overflow-auto whitespace-pre-wrap rounded bg-gray-900 p-3 text-xs text-gray-100">{{ $execution->output }}</pre>
                                            @elseif (in_array($execution->status, \App\Models\ServerCommandExecution::ACTIVE_STATUSES, true))
                                                <p class="mt-2 text-xs text-secondary">{{ __('Waiting for command output…') }}</p>
                                            @endif
                                            <div class="mt-2 flex items-center justify-between gap-3">
                                                <p class="text-xs text-secondary">
                                                    {{ $execution->created_at->diffForHumans() }}
                                                    @if ($execution->exit_code !== null)
                                                        · {{ __('exit :code', ['code' => $execution->exit_code]) }}
                                                    @endif
                                                </p>
                                                <div class="flex items-center gap-3">
                                                    @if ($execution->output !== null)
                                                        <a href="{{ route('servers.commands.output', ['server' => $model, 'execution' => $execution]) }}" class="text-xs font-medium text-ternary hover:underline">
                                                            {{ __('Download') }}
                                                        </a>
                                                    @endif
                                                    @if ($execution->status === \App\Models\ServerCommandExecution::STATUS_QUEUED)
                                                        <button
                                                            type="button"
                                                            wire:click="cancel({{ $execution->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="cancel({{ $execution->id }})"
                                                            class="text-xs font-medium text-red-500 hover:underline"
                                                        >
                                                            {{ __('Cancel') }}
                                                        </button>
                                                    @endif
                                                    @if ($model->provisioning_status === \App\Models\Server::STATUS_ACTIVE
                                                        && in_array($execution->status, \App\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                                                        <button
                                                            type="button"
                                                            wire:click="rerun({{ $execution->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="rerun({{ $execution->id }})"
                                                            class="text-xs font-medium text-ternary hover:underline"
                                                        >
                                                            {{ __('Run again') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-sm text-secondary">{{ __('No commands have been run on this server yet.') }}</p>
                                    @endforelse
                                </div>
                            </div>
                            <div class="flex flex-row-reverse gap-2 border-t border-primary bg-secondary px-6 py-3">
                                <button type="submit" class="button tertiary" wire:loading.attr="disabled" wire:target="run">
                                    <span wire:loading.remove wire:target="run">{{ __('Run command') }}</span>
                                    <span wire:loading wire:target="run">{{ __('Running…') }}</span>
                                </button>
                                <button type="button" wire:click="close" class="button primary">{{ __('Close') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
