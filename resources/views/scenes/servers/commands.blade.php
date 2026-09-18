<x-layouts.app>
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

    <section class="ui-card mb-6 p-4 sm:p-5" aria-labelledby="server-command-filters-heading">
        <div class="mb-4">
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Find an operation') }}</p>
            <h2 id="server-command-filters-heading" class="mt-1 text-lg font-bold text-primary">{{ __('Filter command history') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Narrow the list by outcome, output retention and queue date.') }}</p>
        </div>
        <form method="GET" action="{{ route('servers.commands.index', $server) }}">
            @error('command')
                <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
            @enderror
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label for="status" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Status') }}</label>
                    <select id="status" name="status" class="input secondary mt-1 w-full rounded-lg">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statuses as $option)
                            <option value="{{ $option }}" @selected($filters['status'] === $option)>
                                {{ str($option)->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="output" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Output') }}</label>
                    <select id="output" name="output" class="input secondary mt-1 w-full rounded-lg">
                        <option value="">{{ __('Any output state') }}</option>
                        <option value="available" @selected($filters['output'] === 'available')>{{ __('Output retained') }}</option>
                        <option value="missing" @selected($filters['output'] === 'missing')>{{ __('No output retained') }}</option>
                    </select>
                </div>
                <div>
                    <label for="date_from" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Queued from') }}</label>
                    <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-1 w-full rounded-lg">
                </div>
                <div>
                    <label for="date_to" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Queued through') }}</label>
                    <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-1 w-full rounded-lg">
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
                <p id="server-command-refresh-help" class="mt-3 text-xs text-secondary">
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

    <x-ui.card class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-primary">
                <caption class="sr-only">{{ __('Server command history') }}</caption>
                <thead class="bg-secondary">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Command') }}</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Status') }}</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Timing') }}</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary">
                    @forelse ($executions as $execution)
                        @php($statusTone = match ($execution->status) {
                            \App\Models\ServerCommandExecution::STATUS_SUCCEEDED => 'success',
                            \App\Models\ServerCommandExecution::STATUS_FAILED => 'danger',
                            \App\Models\ServerCommandExecution::STATUS_CANCELED => 'warning',
                            default => 'accent',
                        })
                        <tr class="align-top">
                            <td class="max-w-xl px-4 py-4">
                                <code class="break-all text-xs text-primary">{{ $execution->command }}</code>
                                @if ($execution->exit_code !== null)
                                    <p class="mt-2 text-xs text-secondary">{{ __('Exit code: :code', ['code' => $execution->exit_code]) }}</p>
                                @endif
                                @if ($execution->rerun_from_execution_id)
                                    <p class="mt-2 text-xs text-secondary">{{ __('Rerun of command #:id', ['id' => $execution->rerun_from_execution_id]) }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-ui.badge :tone="$statusTone">{{ $execution->status }}</x-ui.badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-xs text-secondary">
                                <span class="block">{{ __('Queued :time', ['time' => $execution->created_at->diffForHumans()]) }}</span>
                                @if ($execution->started_at)
                                    <span class="mt-1 block">{{ __('Started :time', ['time' => $execution->started_at->diffForHumans()]) }}</span>
                                @endif
                                @if ($execution->finished_at)
                                    <span class="mt-1 block">{{ __('Finished :time', ['time' => $execution->finished_at->diffForHumans()]) }}</span>
                                @endif
                                <span class="mt-1 block">{{ __('Duration: :duration', ['duration' => $execution->durationLabel() ?? __('Not recorded')]) }}</span>
                            </td>
                            <td class="px-4 py-4 text-right align-top text-sm">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($execution->output !== null)
                                        <x-ui.button :href="route('servers.commands.output', ['server' => $server, 'execution' => $execution])" variant="secondary" class="whitespace-nowrap">
                                            {{ __('Download output') }}
                                        </x-ui.button>
                                    @endif
                                    @if ($execution->status === \App\Models\ServerCommandExecution::STATUS_QUEUED)
                                        <form method="POST" action="{{ route('servers.commands.cancel', ['server' => $server, 'execution' => $execution]) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="danger" class="whitespace-nowrap" onclick="return confirm({{ Illuminate\Support\Js::from(__('Cancel this queued command?')) }})">
                                                {{ __('Cancel') }}
                                            </x-ui.button>
                                        </form>
                                    @endif
                                    @if ($server->provisioning_status === \App\Models\Server::STATUS_ACTIVE
                                        && in_array($execution->status, \App\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                                        <form method="POST" action="{{ route('servers.commands.rerun', ['server' => $server, 'execution' => $execution]) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="primary" class="whitespace-nowrap" onclick="return confirm({{ Illuminate\Support\Js::from(__('Run this command again as root?')) }})">
                                                {{ __('Run again') }}
                                            </x-ui.button>
                                        </form>
                                    @endif
                                    @if (in_array($execution->status, \App\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                                        <form method="POST" action="{{ route('servers.commands.destroy', ['server' => $server, 'execution' => $execution]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="danger" class="whitespace-nowrap" onclick="return confirm({{ Illuminate\Support\Js::from(__('Delete this command and its retained output?')) }})">
                                                {{ __('Delete') }}
                                            </x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="p-4">
                                <x-ui.empty-state
                                    :title="array_filter($filters, fn ($value) => $value !== null) ? __('No commands match these filters') : __('No commands have been run on this server yet')"
                                >
                                    @if (array_filter($filters, fn ($value) => $value !== null))
                                        <x-slot:action>
                                            <x-ui.button :href="route('servers.commands.index', $server)" variant="ghost">{{ __('Clear filter') }}</x-ui.button>
                                        </x-slot:action>
                                    @endif
                                </x-ui.empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-6">
        {{ $executions->links() }}
    </div>
</x-layouts.app>
