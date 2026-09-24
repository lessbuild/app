<x-layouts.app>
    @php
        $budgetDialogOpen = request()->query('dialog') === 'edit-budget'
            || $errors->has('monthly_infrastructure_budget');
        $budgetDialogUrl = route('costs.index', ['dialog' => 'edit-budget']);
    @endphp

    <x-layouts.partials.heading
        eyebrow="{{ __('Workspace economics') }}"
        icon="chip"
        :title="__('Cost visibility')"
        :description="__('Provider-catalog estimates, measured utilization signals, and budget awareness. Your provider invoice remains authoritative.')"
    >
        <x-slot:buttons>
            <x-signal.ui.button :href="route('billing.index')" variant="secondary">{{ __('Manage billing') }}</x-signal.ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @unless($featureAvailable)
        <div class="ui-alert ui-alert--info mt-6" role="status">
            <strong class="text-ink">{{ __('Pro feature') }}</strong>
            · {{ __('Upgrade to save workspace budgets. Read-only estimates remain available.') }}
            <a href="{{ route('pricing') }}" class="ui-link font-bold">{{ __('Compare plans') }}</a>
        </div>
    @endunless

    @php
        $costSummaryOpen = $idleCount > 0 || $unknownCount > 0;
    @endphp

    <x-signal.ui.insights
        id="cost-summary"
        class="mt-8"
        :open="$costSummaryOpen"
        :mobile-open="$costSummaryOpen"
        :summary="'$'.number_format($estimated, 2).' '.__('estimated monthly')"
    >
        <div class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                [__('Estimated monthly'), '$'.number_format($estimated, 2)],
                [__('Configured servers'), $rows->count()],
                [__('Needs attention'), $idleCount],
                [__('Unknown prices'), $unknownCount],
            ] as [$label, $value])
                <x-signal.ui.stat :label="$label" :value="$value" />
            @endforeach
        </div>
    </x-signal.ui.insights>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_22rem]">
        <section class="ui-card overflow-hidden">
            <div class="border-b border-line p-5">
                <h2 class="text-xl font-extrabold text-ink">{{ __('Resource estimates') }}</h2>
                <p class="mt-1 text-sm text-muted">
                    {{ __('Monthly amounts are provider-catalog estimates, not provider billing. They exclude taxes, bandwidth overages, storage, discounts, and resources created outside :app.', ['app' => config('app.name')]) }}
                </p>
            </div>

            <div class="divide-y divide-line">
                @forelse($rows as $row)
                    <article class="grid gap-4 p-5 sm:grid-cols-[1fr_auto_auto] sm:items-center">
                        <div>
                            <a class="ui-link font-bold" href="{{ route('servers.show', $row->server) }}">
                                {{ $row->server->label }}
                            </a>
                            <p class="text-xs text-muted">
                                {{ $row->server->provider?->name }}
                                · {{ $row->server->size ?: __('Unknown size') }}
                                · {{ trans_choice(':count website|:count websites', $row->server->websites_count, ['count' => $row->server->websites_count]) }}
                            </p>
                            @if($row->attribution === 'direct')
                                <p class="text-xs text-muted">{{ __('Linked to :project', ['project' => $row->projectNames[0]]) }}</p>
                            @elseif($row->attribution === 'shared')
                                <p class="text-xs text-muted">{{ __('Shared across :count projects: :projects', ['count' => count($row->projectNames), 'projects' => implode(', ', $row->projectNames)]) }}</p>
                            @else
                                <p class="text-xs text-warning">{{ __('No project attribution; cost remains at server level.') }}</p>
                            @endif
                        </div>

                        <div class="text-sm text-muted sm:text-right">
                            <span class="block">
                                {{ $row->averageCpu === null ? __('No CPU sample') : __(':value% average CPU', ['value' => round($row->averageCpu)]) }}
                            </span>
                            <span class="text-xs">{{ __('Measured CPU telemetry') }}</span>
                        </div>

                        <div class="text-right">
                            <p class="font-extrabold text-ink">
                                {{ $row->monthly === null ? '—' : '$'.number_format($row->monthly, 2).'/mo' }}
                            </p>
                            @if($row->monthly !== null)
                                @if($row->catalogObservedAt)
                                    <span class="block text-xs text-muted">
                                        {{ __('Catalog observed :date', ['date' => $row->catalogObservedAt->toDayDateTimeString()]) }}
                                    </span>
                                @else
                                    <span class="block text-xs text-warning">{{ __('Catalog observation unavailable') }}</span>
                                @endif
                            @else
                                <span class="block text-xs text-warning">{{ __('Price source unavailable') }}</span>
                            @endif
                            @if($row->idle)
                                <x-signal.ui.badge tone="warning">{{ __('Review or hibernate') }}</x-signal.ui.badge>
                            @endif
                        </div>
                    </article>
                @empty
                    <x-signal.ui.empty-state :title="__('No resource estimates')" :description="__('Provision or import a server to begin tracking estimates.')" icon="server" />
                @endforelse
            </div>
        </section>

        <aside class="space-y-5">
            <section class="ui-card p-5">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="font-extrabold text-ink">{{ __('Monthly budget') }}</h2>
                    @if($canManage)
                        <x-signal.ui.button
                            :href="$budgetDialogUrl"
                            data-modal-trigger="cost-budget-dialog"
                            aria-controls="cost-budget-dialog"
                            aria-expanded="{{ $budgetDialogOpen ? 'true' : 'false' }}"
                            variant="ghost"
                            class="-mr-2 -mt-2"
                        >
                            {{ __('Edit') }}
                        </x-signal.ui.button>
                    @endif
                </div>
                @if($budget)
                    <p class="mt-2 text-2xl font-extrabold text-ink">{{ '$'.number_format($budget, 2) }}</p>
                    <p class="mt-1 text-sm {{ $estimated > $budget ? 'text-danger' : 'text-muted' }}">
                        {{ $estimated > $budget ? __('Estimate exceeds budget by $:amount.', ['amount' => number_format($estimated - $budget, 2)]) : __('$:amount estimated headroom.', ['amount' => number_format($budget - $estimated, 2)]) }}
                    </p>
                @else
                    <p class="mt-2 text-sm text-muted">{{ __('Set a planning threshold to make cost changes visible before they become surprises.') }}</p>
                @endif

            </section>

            @if($canManage)
                <x-scenes.costs.budget-dialog
                    :budget="$budget"
                    :open="$budgetDialogOpen"
                />
            @endif

            <section class="ui-card border-l-4 border-line bg-surface-muted p-5" style="border-left-color: var(--ui-primary)">
                <h2 class="font-extrabold">{{ __('Cost basis') }}</h2>
                <ul class="mt-3 space-y-2 text-sm text-muted">
                    <li>• {{ __('Monthly amount: stored provider-catalog estimate.') }}</li>
                    <li>• {{ __('CPU: measured :app telemetry, not billing usage.', ['app' => config('app.name')]) }}</li>
                    <li>• {{ __('Provider billing: not connected; invoice remains authoritative.') }}</li>
                </ul>
            </section>

            <section class="ui-card p-5">
                <h2 class="font-extrabold text-ink">{{ __('Preview lifetime') }}</h2>
                <p class="mt-2 text-sm text-muted">
                    @if($previewUsage->limit === null)
                        {{ trans_choice(':count active preview environment; no configured plan quota.|:count active preview environments; no configured plan quota.', $previewUsage->used, ['count' => $previewUsage->used]) }}
                    @else
                        {{ __(':used of :limit preview environments in use; quota is not a monetary limit.', ['used' => $previewUsage->used, 'limit' => $previewUsage->limit]) }}
                    @endif
                </p>
                @if($previewUsage->previews->isEmpty())
                    <p class="mt-3 text-sm text-muted">{{ __('No active previews are using workspace capacity.') }}</p>
                @else
                    <ul class="mt-3 space-y-3 text-sm">
                        @foreach($previewUsage->previews as $lifetime)
                            <li class="border-t border-line pt-3 first:border-0 first:pt-0">
                                <p class="font-bold text-ink">{{ $lifetime->project->name }} · PR #{{ $lifetime->preview->pull_request_number }}</p>
                                <p class="mt-1 text-muted">{{ $lifetime->preview->status }} · {{ __(':count-hour configured lifetime', ['count' => $lifetime->ttlHours]) }}</p>
                                @if($lifetime->expired)
                                    <p class="mt-1 font-bold text-warning">{{ __('Past configured lifetime; cleanup is pending.') }}</p>
                                    <a class="ui-link mt-1 inline-block text-xs" href="{{ route('projects.show', $lifetime->project) }}">{{ __('Review preview') }}</a>
                                @else
                                    <p class="mt-1 text-muted">{{ __('Expires :date', ['date' => $lifetime->expiresAt->toDayDateTimeString()]) }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($previewUsage->hiddenCount > 0)
                    <p class="mt-3 text-xs text-muted">{{ __('Showing the first :count active previews; quota usage includes all active previews.', ['count' => $previewUsage->previews->count()]) }}</p>
                @endif
            </section>

            <section class="ui-card p-5">
                <h2 class="font-extrabold text-ink">{{ __('Optimization signals') }}</h2>
                <ul class="mt-3 space-y-2 text-sm text-muted">
                    <li>• {{ __('Servers without websites are flagged.') }}</li>
                    <li>• {{ __('Sustained CPU below 10% is flagged for review.') }}</li>
                    <li>• {{ __('Use Automation to hibernate eligible environments.') }}</li>
                </ul>
            </section>
        </aside>
    </div>
</x-layouts.app>
