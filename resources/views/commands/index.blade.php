<x-layouts.app>
    <x-layouts.partials.heading
        icon="terminal"
        :title="__('Command Center')"
        :description="__('Review command activity across every server without exposing command text or retained output.')"
    />

    <x-ui.card class="mt-8 p-4">
        <form method="GET" action="{{ route('commands.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <div>
                <label for="server_id" class="block text-xs font-semibold uppercase text-secondary">{{ __('Server') }}</label>
                <select id="server_id" name="server_id" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All servers') }}</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected((int) $filters['server_id'] === $server->id)>{{ $server->label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ str($status)->title() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="output" class="block text-xs font-semibold uppercase text-secondary">{{ __('Output') }}</label>
                <select id="output" name="output" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('Any output state') }}</option>
                    <option value="available" @selected($filters['output'] === 'available')>{{ __('Output retained') }}</option>
                    <option value="missing" @selected($filters['output'] === 'missing')>{{ __('No output retained') }}</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex min-h-[42px] w-full items-center gap-2 rounded-sm border border-primary px-3 text-sm text-primary">
                    <input type="checkbox" name="active" value="1" @checked($filters['active'])>
                    {{ __('Active commands only') }}
                </label>
            </div>
            <div>
                <label for="date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('Queued from') }}</label>
                <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-1 w-full rounded-sm">
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Queued through') }}</label>
                <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-1 w-full rounded-sm">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
            @if ($metrics['active'] > 0)
                <x-ui.button
                    :href="route('commands.index', [...array_filter($filters, fn ($value) => $value !== null), 'page' => $executions->currentPage()])"
                    variant="secondary"
                    aria-describedby="command-refresh-help"
                >
                    {{ __('Refresh status') }}
                </x-ui.button>
            @endif
            <x-ui.button :href="route('commands.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">{{ __('Export CSV') }}</x-ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-ui.button :href="route('commands.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>
        @if ($metrics['active'] > 0)
            <p id="command-refresh-help" class="mt-3 text-xs text-secondary">
                {{ __('Queued or running commands may change. Refresh to load their latest state.') }}
            </p>
        @endif
        </form>
    </x-ui.card>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        @foreach ([
            ['label' => __('Matching commands'), 'value' => $metrics['total']],
            ['label' => __('Active'), 'value' => $metrics['active']],
            ['label' => __('Succeeded'), 'value' => $metrics['succeeded']],
            ['label' => __('Failed'), 'value' => $metrics['failed']],
            ['label' => __('Canceled'), 'value' => $metrics['canceled']],
        ] as $metric)
            <x-ui.stat :label="$metric['label']" :value="$metric['value']" />
        @endforeach
        <x-ui.stat :label="__('Latest matching')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" />
    </dl>

    <div class="ui-card mt-6 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-primary">
            <thead class="bg-secondary">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Execution') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Server') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Output') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Timing') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-secondary">{{ __('Details') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary">
                @forelse ($executions as $execution)
                    <tr>
                        <td class="px-4 py-4 text-sm font-medium text-primary">#{{ $execution->id }}</td>
                        <td class="px-4 py-4 text-sm text-primary">{{ $execution->server->label }}</td>
                        <td class="px-4 py-4">
                            <x-ui.badge tone="{{ in_array($execution->status, ['succeeded', 'completed'], true) ? 'success' : (in_array($execution->status, ['failed', 'error'], true) ? 'danger' : 'accent') }}">{{ $execution->status }}</x-ui.badge>
                        </td>
                        <td class="px-4 py-4 text-xs text-secondary">
                            <x-ui.badge tone="{{ $execution->output_available ? 'success' : 'neutral' }}">{{ $execution->output_available ? __('Retained') : __('Not retained') }}</x-ui.badge>
                        </td>
                        <td class="px-4 py-4 text-xs text-secondary">
                            <span class="block">{{ __('Queued :time', ['time' => $execution->created_at->diffForHumans()]) }}</span>
                            @if ($execution->started_at)
                                <span class="mt-1 block">{{ __('Started :time', ['time' => $execution->started_at->diffForHumans()]) }}</span>
                            @endif
                            @if ($execution->finished_at)
                                <span class="mt-1 block">{{ __('Finished :time', ['time' => $execution->finished_at->diffForHumans()]) }}</span>
                            @endif
                            <span class="mt-1 block">{{ __('Duration: :duration', ['duration' => $execution->durationLabel() ?? __('Not recorded')]) }}</span>
                        </td>
                        <td class="px-4 py-4 text-right">
                            <x-ui.button :href="route('servers.commands.index', ['server' => $execution->server, 'execution' => $execution->id])" variant="secondary">
                                {{ __('Open server history') }}
                            </x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center">
                            <p class="font-medium text-primary">{{ array_filter($filters, fn ($value) => $value !== null) ? __('No commands match these filters') : __('No commands have been run yet') }}</p>
                            <p class="mt-1 text-sm text-secondary">{{ __('Run a command from an active server to see its lifecycle here.') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-6">{{ $executions->links() }}</div>
</x-layouts.app>
