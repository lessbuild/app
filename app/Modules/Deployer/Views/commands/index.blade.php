<x-layouts.app>
    <x-layouts.partials.heading
        eyebrow="{{ __('Server operations') }}"
        icon="terminal"
        :title="__('Command Center')"
        :description="__('Review command activity across every server without exposing command text or retained output.')"
    />

    <x-signal.ui.local-nav :label="__('Command center sections')">
        <a href="#command-filters" class="ui-local-nav__link">{{ __('Filters') }}</a>
        <a href="#command-insights" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#command-history" class="ui-local-nav__link">{{ __('History') }}</a>
    </x-signal.ui.local-nav>

    @php
        $commandFilterCount = collect($filters)->filter(fn ($value) => filled($value))->count();
    @endphp

    <x-signal.ui.filter-panel
        id="command-filters"
        class="mt-8"
        :open="$commandFilterCount > 0"
        :summary="$commandFilterCount > 0 ? trans_choice(':count active filter|:count active filters', $commandFilterCount, ['count' => $commandFilterCount]) : null"
    >
        <div class="mb-4">
            <p class="ui-eyebrow">{{ __('Find an operation') }}</p>
            <h2 id="command-filters-heading" class="mt-1 text-lg font-bold text-ink">{{ __('Filter command activity') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Review bounded command metadata across your servers without exposing command text or retained output.') }}</p>
        </div>
        <form method="GET" action="{{ route('commands.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <div>
                <label for="server_id" class="ui-label">{{ __('Server') }}</label>
                <x-signal.ui.select id="server_id" name="server_id" class="ui-input">
                    <option value="">{{ __('All servers') }}</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected((int) $filters['server_id'] === $server->id)>{{ $server->label }}</option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="status" class="ui-label">{{ __('Status') }}</label>
                <x-signal.ui.select id="status" name="status" class="ui-input">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ str($status)->title() }}</option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="output" class="ui-label">{{ __('Output') }}</label>
                <x-signal.ui.select id="output" name="output" class="ui-input">
                    <option value="">{{ __('Any output state') }}</option>
                    <option value="available" @selected($filters['output'] === 'available')>{{ __('Output retained') }}</option>
                    <option value="missing" @selected($filters['output'] === 'missing')>{{ __('No output retained') }}</option>
                </x-signal.ui.select>
            </div>
            <div class="flex items-end">
                <label class="ui-choice min-h-11 w-full items-center">
                    <x-signal.ui.input type="checkbox" name="active" value="1" @checked($filters['active']) class="ui-check" :restore="false" />
                    {{ __('Active commands only') }}
                </label>
            </div>
            <div>
                <label for="date_from" class="ui-label">{{ __('Queued from') }}</label>
                <x-signal.ui.input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="ui-input" :restore="false" />
            </div>
            <div>
                <label for="date_to" class="ui-label">{{ __('Queued through') }}</label>
                <x-signal.ui.input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="ui-input" :restore="false" />
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
            @if ($metrics['active'] > 0)
                <x-signal.ui.button
                    :href="route('commands.index', [...array_filter($filters, fn ($value) => $value !== null), 'page' => $executions->currentPage()])"
                    variant="secondary"
                    aria-describedby="command-refresh-help"
                >
                    {{ __('Refresh status') }}
                </x-signal.ui.button>
            @endif
            <x-signal.ui.button :href="route('commands.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">{{ __('Export CSV') }}</x-signal.ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-signal.ui.button :href="route('commands.index')" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
            @endif
        </div>
        @if ($metrics['active'] > 0)
            <p id="command-refresh-help" class="mt-3 text-xs text-muted">
                {{ __('Queued or running commands may change. Refresh to load their latest state.') }}
            </p>
        @endif
        </form>
    </x-signal.ui.filter-panel>

    <x-signal.ui.insights
        id="command-insights"
        class="mt-6"
        :open="$metrics['active'] > 0"
        :mobile-open="$metrics['active'] > 0"
        :summary="trans_choice(':count active command|:count active commands', $metrics['active'], ['count' => $metrics['active']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            @foreach ([
                ['label' => __('Matching commands'), 'value' => $metrics['total']],
                ['label' => __('Active'), 'value' => $metrics['active']],
                ['label' => __('Succeeded'), 'value' => $metrics['succeeded']],
                ['label' => __('Failed'), 'value' => $metrics['failed']],
                ['label' => __('Canceled'), 'value' => $metrics['canceled']],
            ] as $metric)
                <x-signal.ui.stat :label="$metric['label']" :value="$metric['value']" />
            @endforeach
            <x-signal.ui.stat :label="__('Latest matching')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" />
        </dl>
    </x-signal.ui.insights>

    <x-signal.ui.panel id="command-history" class="ui-panel ui-inventory-list mt-6 scroll-mt-24 overflow-hidden">
        <div class="divide-y divide-line" aria-label="{{ __('Command activity across all servers') }}">
            @forelse ($executions as $execution)
                <article data-command-execution class="p-4 transition-colors hover:bg-surface-muted sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="ui-eyebrow text-[0.65rem]">{{ __('Execution #:id', ['id' => $execution->id]) }}</p>
                            <h2 class="mt-1 text-base font-semibold text-ink">{{ $execution->server->label }}</h2>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-signal.ui.badge tone="{{ in_array($execution->status, ['succeeded', 'completed'], true) ? 'success' : (in_array($execution->status, ['failed', 'error'], true) ? 'danger' : 'accent') }}">{{ $execution->status }}</x-signal.ui.badge>
                            <x-signal.ui.badge tone="{{ $execution->output_available ? 'success' : 'neutral' }}">{{ $execution->output_available ? __('Retained') : __('Not retained') }}</x-signal.ui.badge>
                        </div>
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

                    <div class="mt-4 flex justify-start sm:justify-end">
                        <x-signal.ui.button :href="route('servers.commands.index', ['server' => $execution->server, 'execution' => $execution->id])" variant="secondary" class="ui-btn-sm">
                            {{ __('Open server history') }}
                        </x-signal.ui.button>
                    </div>
                </article>
            @empty
                <div class="p-6 text-center">
                    <x-signal.ui.empty-state
                        :title="array_filter($filters, fn ($value) => $value !== null) ? __('No commands match these filters') : __('No commands have been run yet')"
                        :description="__('Run a command from an active server to see its lifecycle here.')"
                    />
                </div>
            @endforelse
        </div>
    </x-signal.ui.panel>

    <div class="mt-6">{{ $executions->links() }}</div>
</x-layouts.app>
