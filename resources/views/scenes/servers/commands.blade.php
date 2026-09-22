<x-layouts.app>
    @php
        $outputDialogId = 'server-command-output-dialog';
        $outputDialogOpen = $selectedOutputExecution !== null;
        $outputDialogExecutionId = $selectedOutputExecution?->id;
        $outputDialogContentUrl = $selectedOutputExecution
            ? route('servers.commands.output', [
                'server' => $server,
                'execution' => $selectedOutputExecution,
                'fragment' => 'server-command-output',
            ])
            : null;
        $outputDialogQuery = array_filter([
            ...$filters,
            'page' => $executions->currentPage() > 1 ? $executions->currentPage() : null,
        ], fn ($value) => $value !== null);
        $outputDialogHistoryUrl = $selectedOutputExecution
            ? route('servers.commands.index', [
                'server' => $server,
                ...$outputDialogQuery,
                'dialog' => "server-command-output-{$selectedOutputExecution->id}",
            ])
            : null;
        $selectedOutputIsOnPage = $selectedOutputExecution
            && $executions->contains('id', $selectedOutputExecution->id);
    @endphp

    <x-layouts.partials.breadcrumbs
        :route="route('servers.show', $server)"
        :title="__('Back to :server', ['server' => $server->label])"
    />

    <x-layouts.partials.heading
        icon="terminal"
        :title="__('Command history')"
        :description="__('Review commands queued for :server and download their retained output.', ['server' => $server->label])"
    />

    @if ($filters['execution'])
        <x-ui.alert tone="info" class="mb-6">
            {{ __('Focused on execution #:id.', ['id' => $filters['execution']]) }}
        </x-ui.alert>
    @endif

    <section class="ui-panel mb-6 p-4 sm:p-5" aria-labelledby="server-command-filters-heading">
        <div class="mb-4">
            <p class="ui-eyebrow">{{ __('Find an operation') }}</p>
            <h2 id="server-command-filters-heading" class="mt-1 text-lg font-bold text-ink">{{ __('Filter command history') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Narrow the list by outcome, output retention and queue date.') }}</p>
        </div>
        <form method="GET" action="{{ route('servers.commands.index', $server) }}">
            @error('command')
                <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
            @enderror
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label for="status" class="ui-label">{{ __('Status') }}</label>
                    <select id="status" name="status" class="ui-input">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statuses as $option)
                            <option value="{{ $option }}" @selected($filters['status'] === $option)>
                                {{ str($option)->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="output" class="ui-label">{{ __('Output') }}</label>
                    <select id="output" name="output" class="ui-input">
                        <option value="">{{ __('Any output state') }}</option>
                        <option value="available" @selected($filters['output'] === 'available')>{{ __('Output retained') }}</option>
                        <option value="missing" @selected($filters['output'] === 'missing')>{{ __('No output retained') }}</option>
                    </select>
                </div>
                <div>
                    <label for="date_from" class="ui-label">{{ __('Queued from') }}</label>
                    <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="ui-input">
                </div>
                <div>
                    <label for="date_to" class="ui-label">{{ __('Queued through') }}</label>
                    <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="ui-input">
                </div>
            </div>
            <div class="mt-5 flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="primary">{{ __('Apply filter') }}</x-ui.button>
                @if ($metrics['active'] > 0)
                    <x-ui.button
                        :href="route('servers.commands.index', ['server' => $server, ...array_filter($filters, fn ($value) => $value !== null), 'page' => $executions->currentPage()])"
                        variant="secondary"
                        aria-describedby="server-command-refresh-help"
                    >
                        {{ __('Refresh status') }}
                    </x-ui.button>
                @endif
                <x-ui.button :href="route('servers.commands.export', [$server, ...array_filter($filters, fn ($value) => $value !== null)])" variant="secondary">
                    {{ __('Export CSV') }}
                </x-ui.button>
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-ui.button :href="route('servers.commands.index', $server)" variant="ghost">{{ __('Clear filter') }}</x-ui.button>
                @endif
            </div>
            @if ($metrics['active'] > 0)
                <p id="server-command-refresh-help" class="mt-3 text-xs text-muted">
                    {{ __('Queued or running commands may change. Refresh to load their latest state.') }}
                </p>
            @endif
        </form>
    </section>

    <x-ui.insights
        id="server-commands-insights"
        class="mb-6"
        :summary="trans_choice(':count matching command|:count matching commands', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat class="ui-card" :label="__('Matching commands')" :value="$metrics['total']" :description="__('Commands in this filtered view.')" />
            <x-ui.stat class="ui-card" :label="__('Active commands')" :value="$metrics['active']" :description="__('Queued or running commands.')" />
            <x-ui.stat class="ui-card" :label="__('Succeeded')" :value="$metrics['succeeded']" :description="__('Matching successful commands.')" />
            <x-ui.stat class="ui-card" :label="__('Failed')" :value="$metrics['failed']" :description="__('Matching failed commands.')" />
            <x-ui.stat class="ui-card" :label="__('Canceled')" :value="$metrics['canceled']" :description="__('Matching canceled commands.')" />
            <x-ui.stat class="ui-card" :label="__('Output retained')" :value="$metrics['output']" :description="__('Matching commands with downloadable output.')" />
        </dl>
    </x-ui.insights>

    <div class="ui-panel ui-inventory-list overflow-hidden">
        <div class="divide-y divide-line" aria-label="{{ __('Server command history') }}">
            @forelse ($executions as $execution)
                @php($statusTone = match ($execution->status) {
                    \App\Models\ServerCommandExecution::STATUS_SUCCEEDED => 'success',
                    \App\Models\ServerCommandExecution::STATUS_FAILED => 'danger',
                    \App\Models\ServerCommandExecution::STATUS_CANCELED => 'warning',
                    default => 'accent',
                })
                <article data-command-execution class="p-4 transition-colors hover:bg-surface-muted sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="ui-eyebrow text-[0.65rem]">{{ __('Command execution #:id', ['id' => $execution->id]) }}</p>
                            <code class="mt-2 block break-all rounded-card border border-line bg-surface-muted px-3 py-2 font-mono text-xs text-ink">{{ $execution->command }}</code>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                                @if ($execution->exit_code !== null)
                                    <span>{{ __('Exit code: :code', ['code' => $execution->exit_code]) }}</span>
                                @endif
                                @if ($execution->rerun_from_execution_id)
                                    <span>{{ __('Rerun of command #:id', ['id' => $execution->rerun_from_execution_id]) }}</span>
                                @endif
                            </div>
                        </div>
                        <x-ui.badge :tone="$statusTone">{{ $execution->status }}</x-ui.badge>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Queued') }}</dt>
                            <dd class="mt-1 text-ink">{{ $execution->created_at->diffForHumans() }}</dd>
                        </div>
                        @if ($execution->started_at)
                            <div>
                                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Started') }}</dt>
                                <dd class="mt-1 text-ink">{{ $execution->started_at->diffForHumans() }}</dd>
                            </div>
                        @endif
                        @if ($execution->finished_at)
                            <div>
                                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Finished') }}</dt>
                                <dd class="mt-1 text-ink">{{ $execution->finished_at->diffForHumans() }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Duration') }}</dt>
                            <dd class="mt-1 text-ink">{{ $execution->durationLabel() ?? __('Not recorded') }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 flex flex-wrap justify-start gap-2 sm:justify-end">
                        @if ($execution->output !== null)
                            @php($outputDialogUrl = route('servers.commands.index', [
                                'server' => $server,
                                ...$outputDialogQuery,
                                'dialog' => "server-command-output-{$execution->id}",
                            ]))
                            @php($outputContentUrl = route('servers.commands.output', [
                                'server' => $server,
                                'execution' => $execution,
                                'fragment' => 'server-command-output',
                            ]))
                            <x-ui.button
                                :href="$outputDialogUrl"
                                data-modal-trigger="{{ $outputDialogId }}"
                                data-modal-content-url="{{ $outputContentUrl }}"
                                data-modal-history-url="{{ $outputDialogUrl }}"
                                aria-controls="{{ $outputDialogId }}"
                                aria-expanded="{{ $outputDialogExecutionId === $execution->id ? 'true' : 'false' }}"
                                variant="primary"
                                class="ui-btn-sm whitespace-nowrap"
                            >
                                {{ __('View output') }}
                            </x-ui.button>
                            <x-ui.button :href="route('servers.commands.output', ['server' => $server, 'execution' => $execution])" variant="secondary" class="ui-btn-sm whitespace-nowrap">
                                {{ __('Download output') }}
                            </x-ui.button>
                        @endif
                        @if ($execution->status === \App\Models\ServerCommandExecution::STATUS_QUEUED)
                            <form method="POST" action="{{ route('servers.commands.cancel', ['server' => $server, 'execution' => $execution]) }}">
                                @csrf
                                <x-ui.button type="submit" variant="danger" class="ui-btn-sm whitespace-nowrap" onclick="return confirm({{ Illuminate\Support\Js::from(__('Cancel this queued command?')) }})">
                                    {{ __('Cancel') }}
                                </x-ui.button>
                            </form>
                        @endif
                        @if ($server->provisioning_status === \App\Models\Server::STATUS_ACTIVE
                            && in_array($execution->status, \App\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                            <form method="POST" action="{{ route('servers.commands.rerun', ['server' => $server, 'execution' => $execution]) }}">
                                @csrf
                                <x-ui.button type="submit" variant="primary" class="ui-btn-sm whitespace-nowrap" onclick="return confirm({{ Illuminate\Support\Js::from(__('Run this command again as root?')) }})">
                                    {{ __('Run again') }}
                                </x-ui.button>
                            </form>
                        @endif
                        @if (in_array($execution->status, \App\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                            <form method="POST" action="{{ route('servers.commands.destroy', ['server' => $server, 'execution' => $execution]) }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger" class="ui-btn-sm whitespace-nowrap" onclick="return confirm({{ Illuminate\Support\Js::from(__('Delete this command and its retained output?')) }})">
                                    {{ __('Delete') }}
                                </x-ui.button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="p-4">
                    <x-ui.empty-state
                        :title="array_filter($filters, fn ($value) => $value !== null) ? __('No commands match these filters') : __('No commands have been run on this server yet')"
                    >
                        @if (array_filter($filters, fn ($value) => $value !== null))
                            <x-slot:action>
                                <x-ui.button :href="route('servers.commands.index', $server)" variant="ghost">{{ __('Clear filter') }}</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                </div>
            @endforelse
        </div>
    </div>

    <div class="mt-6">
        {{ $executions->links() }}
    </div>

    @if ($selectedOutputExecution && ! $selectedOutputIsOnPage)
        <a
            href="{{ $outputDialogHistoryUrl }}"
            data-modal-trigger="{{ $outputDialogId }}"
            data-modal-content-url="{{ $outputDialogContentUrl }}"
            data-modal-history-url="{{ $outputDialogHistoryUrl }}"
            aria-controls="{{ $outputDialogId }}"
            aria-expanded="{{ $outputDialogOpen ? 'true' : 'false' }}"
            class="sr-only"
        >{{ __('Open retained output') }}</a>
    @endif

    <x-dialogs.modal
        id="{{ $outputDialogId }}"
        :title="__('Command output')"
        :description="__('Inspect retained output without leaving command history.')"
        :open="$outputDialogOpen"
        body-class="p-0"
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading retained output…') }}</p>
        </div>
    </x-dialogs.modal>
</x-layouts.app>
