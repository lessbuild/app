<x-signal.layouts.platform
    :title="__('Cost breakdown')"
    :description="__('Review product-owned infrastructure cost estimates and budgets for this workspace.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
>
    <x-signal.ui.page-header
        eyebrow="{{ $workspace->name }}"
        :title="__('Cost breakdown')"
        :description="__('View cost estimates from connected apps in one place. Product modules remain the source of their usage and infrastructure records.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.subscriptions', $workspace)" variant="secondary">{{ __('Plans and billing') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="quiet">{{ __('Workspace management') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if ($breakdowns->isEmpty())
        <x-signal.ui.card class="mt-7 p-6">
            <x-signal.ui.empty-state
                :title="$hasDeployerAccess ? __('Cost information is unavailable') : __('No cost reports are connected')"
                :description="$hasDeployerAccess ? __('The Deployer workspace mapping could not be confirmed, so its cost records are hidden.') : __('Cost reports will appear here when an app provides an authorized cost summary for this workspace.')"
                icon="chip"
            />
        </x-signal.ui.card>
    @endif

    @foreach ($breakdowns as $product => $report)
        <section class="mt-7" aria-labelledby="costs-{{ $product }}-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ str($product)->headline() }}</p>
                    <h2 id="costs-{{ $product }}-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Infrastructure estimate') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Provider-catalog estimates and measured utilization. Your provider invoice remains authoritative.') }}</p>
                </div>
                @unless ($report->featureAvailable)
                    <x-signal.ui.badge tone="warning">{{ __('Budget controls require an eligible plan') }}</x-signal.ui.badge>
                @endunless
            </div>

            @unless ($report->featureAvailable)
                <x-signal.ui.alert tone="info" class="mb-4">
                    {{ __('Read-only estimates remain available. Review the separate app plans to see whether budget controls are included.') }}
                    <a href="{{ route('core.workspace.subscriptions', $workspace) }}" class="ui-link font-bold">{{ __('View plans') }}</a>
                </x-signal.ui.alert>
            @endunless

            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-signal.ui.stat :label="__('Estimated monthly')" :value="'$'.number_format($report->estimated, 2)" />
                <x-signal.ui.stat :label="__('Configured servers')" :value="count($report->rows)" />
                <x-signal.ui.stat :label="__('Needs attention')" :value="$report->idleCount" />
                <x-signal.ui.stat :label="__('Unknown prices')" :value="$report->unknownCount" />
            </dl>

            <div class="mt-5 grid gap-5 xl:grid-cols-[1fr_22rem]">
                <x-signal.ui.card as="section" class="overflow-hidden">
                    <div class="border-b border-line p-5">
                        <h3 class="text-lg font-extrabold text-ink">{{ __('Resource estimates') }}</h3>
                        <p class="mt-1 text-sm leading-6 text-muted">{{ __('Monthly amounts use stored provider catalog prices. They exclude taxes, overages, storage, discounts, and resources created outside Deployer.') }}</p>
                    </div>
                    <div class="divide-y divide-line">
                        @forelse ($report->rows as $row)
                            <article class="grid gap-4 p-5 sm:grid-cols-[1fr_auto_auto] sm:items-center">
                                <div class="min-w-0">
                                    <h4 class="font-bold text-ink">{{ $row['label'] }}</h4>
                                    <p class="text-xs text-muted">{{ $row['provider'] ?? __('Provider unavailable') }} · {{ $row['size'] ?: __('Unknown size') }} · {{ trans_choice(':count website|:count websites', $row['websites'], ['count' => $row['websites']]) }}</p>
                                    @if ($row['attribution'] === 'direct')
                                        <p class="mt-1 text-xs text-muted">{{ __('Linked to :project', ['project' => $row['project_names'][0]]) }}</p>
                                    @elseif ($row['attribution'] === 'shared')
                                        <p class="mt-1 text-xs text-muted">{{ __('Shared across :count projects: :projects', ['count' => count($row['project_names']), 'projects' => implode(', ', $row['project_names'])]) }}</p>
                                    @else
                                        <p class="mt-1 text-xs text-warning">{{ __('No project attribution; cost remains at server level.') }}</p>
                                    @endif
                                </div>

                                <div class="text-sm text-muted sm:text-right">
                                    <span class="block">{{ $row['average_cpu'] === null ? __('No CPU sample') : __(':value% average CPU', ['value' => round($row['average_cpu'])]) }}</span>
                                    <span class="text-xs">{{ __('Measured CPU telemetry') }}</span>
                                </div>

                                <div class="text-right">
                                    <p class="font-extrabold text-ink">{{ $row['monthly'] === null ? '—' : '$'.number_format($row['monthly'], 2).'/mo' }}</p>
                                    @if ($row['catalog_observed_at'])
                                        <span class="block text-xs text-muted">{{ __('Catalog observed :date', ['date' => $row['catalog_observed_at']]) }}</span>
                                    @elseif ($row['monthly'] !== null)
                                        <span class="block text-xs text-warning">{{ __('Catalog observation unavailable') }}</span>
                                    @else
                                        <span class="block text-xs text-warning">{{ __('Price source unavailable') }}</span>
                                    @endif
                                    @if ($row['idle'])
                                        <x-signal.ui.badge tone="warning">{{ __('Review or hibernate') }}</x-signal.ui.badge>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <x-signal.ui.empty-state :title="__('No resource estimates')" :description="__('Provision or import a server to begin tracking estimates.')" icon="server" />
                        @endforelse
                    </div>
                </x-signal.ui.card>

                <aside class="grid content-start gap-4">
                    <x-signal.ui.card as="section" class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="font-extrabold text-ink">{{ __('Monthly budget') }}</h3>
                            @if ($report->canManage)
                                <a href="#edit-cost-budget" class="ui-link text-sm font-bold">{{ __('Edit') }}</a>
                            @endif
                        </div>
                        @if ($report->budget !== null)
                            <p class="mt-2 text-2xl font-extrabold text-ink">{{ '$'.number_format($report->budget, 2) }}</p>
                            <p class="mt-1 text-sm {{ $report->estimated > $report->budget ? 'text-danger' : 'text-muted' }}">
                                {{ $report->estimated > $report->budget ? __('Estimate exceeds budget by $:amount.', ['amount' => number_format($report->estimated - $report->budget, 2)]) : __('$:amount estimated headroom.', ['amount' => number_format($report->budget - $report->estimated, 2)]) }}
                            </p>
                        @else
                            <p class="mt-2 text-sm text-muted">{{ __('Set a planning threshold to make cost changes visible before they become surprises.') }}</p>
                        @endif

                        @if ($report->canManage)
                            <form id="edit-cost-budget" method="POST" action="{{ route('core.workspace.costs.budget.update', $workspace) }}" class="mt-4 grid gap-3 border-t border-line pt-4">
                                @csrf
                                @method('PATCH')
                                <label for="monthly_infrastructure_budget" class="ui-label">{{ __('Monthly budget (USD)') }}</label>
                                <x-signal.ui.input
                                    id="monthly_infrastructure_budget"
                                    name="monthly_infrastructure_budget"
                                    type="number"
                                    min="1"
                                    max="1000000"
                                    step="0.01"
                                    :value="old('monthly_infrastructure_budget', $report->budget)"
                                    aria-describedby="monthly-infrastructure-budget-error"
                                />
                                @error('monthly_infrastructure_budget')<p id="monthly-infrastructure-budget-error" class="text-xs font-semibold text-danger">{{ $message }}</p>@enderror
                                <x-signal.ui.button type="submit" variant="primary">{{ __('Save budget') }}</x-signal.ui.button>
                            </form>
                        @endif
                    </x-signal.ui.card>

                    <x-signal.ui.card as="section" class="p-5">
                        <h3 class="font-extrabold text-ink">{{ __('Preview lifetime') }}</h3>
                        @if ($report->previewUsage['limit'] === null)
                            <p class="mt-2 text-sm text-muted">{{ trans_choice(':count active preview environment; no configured plan quota.|:count active preview environments; no configured plan quota.', $report->previewUsage['used'], ['count' => $report->previewUsage['used']]) }}</p>
                        @else
                            <p class="mt-2 text-sm text-muted">{{ __(':used of :limit preview environments in use; quota is not a monetary limit.', ['used' => $report->previewUsage['used'], 'limit' => $report->previewUsage['limit']]) }}</p>
                        @endif
                        @if ($report->previewUsage['previews'] === [])
                            <p class="mt-3 text-sm text-muted">{{ __('No active previews are using workspace capacity.') }}</p>
                        @else
                            <ul class="mt-3 grid gap-3 text-sm">
                                @foreach ($report->previewUsage['previews'] as $preview)
                                    <li class="border-t border-line pt-3 first:border-0 first:pt-0">
                                        <p class="font-bold text-ink">{{ $preview['project_name'] }} @if ($preview['pull_request_number']) · PR #{{ $preview['pull_request_number'] }} @endif</p>
                                        <p class="mt-1 text-muted">{{ $preview['status'] }} · {{ __(':count-hour configured lifetime', ['count' => $preview['ttl_hours']]) }}</p>
                                        @if ($preview['expired'])
                                            <p class="mt-1 font-bold text-warning">{{ __('Past configured lifetime; cleanup is pending.') }}</p>
                                        @elseif ($preview['expires_at'])
                                            <p class="mt-1 text-muted">{{ __('Expires :date', ['date' => $preview['expires_at']]) }}</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($report->previewUsage['hidden_count'] > 0)
                            <p class="mt-3 text-xs text-muted">{{ __('Showing the first :count active previews; quota usage includes all active previews.', ['count' => count($report->previewUsage['previews'])]) }}</p>
                        @endif
                    </x-signal.ui.card>

                    <x-signal.ui.card as="section" class="p-5">
                        <h3 class="font-extrabold text-ink">{{ __('Cost basis') }}</h3>
                        <ul class="mt-3 grid gap-2 text-sm leading-6 text-muted">
                            <li>{{ __('Monthly amounts use stored provider catalog estimates.') }}</li>
                            <li>{{ __('CPU is measured Deployer telemetry, not provider billing usage.') }}</li>
                            <li>{{ __('Provider invoices remain authoritative.') }}</li>
                            <li>{{ __('Sustained CPU below 10% and servers without websites are flagged for review.') }}</li>
                        </ul>
                    </x-signal.ui.card>
                </aside>
            </div>
        </section>
    @endforeach
</x-signal.layouts.platform>
