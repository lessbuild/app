@php($project = $overview->project)
@php($usd = $report['totals']['USD'] ?? 0.0)
@php($money = fn (float $amount, string $currency): string => \Illuminate\Support\Number::currency($amount, $currency))

<x-signal.layouts.project :overview="$overview" :title="__('Costs')" :description="__('What :account’s servers cost a month, from each provider’s price list, and which are idle.', ['account' => $project->account->name])">
    @error('monthly_cost')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror

    <div class="grid gap-4 sm:grid-cols-3">
        <x-signal.ui.stat :label="__('Estimated per month')" :value="$report['totals'] === [] ? '—' : collect($report['totals'])->map(fn ($total, $currency) => $money($total, $currency))->implode(' + ')" :description="$report['unknown'] > 0 ? trans_choice(':count server has no known price|:count servers have no known price', $report['unknown']) : null" />
        <x-signal.ui.stat :label="__('Budget')" :value="$budget === null ? __('Not set') : $money($budget, 'USD')" :description="$budget !== null ? ($usd > $budget ? __('Over by :amount', ['amount' => $money($usd - $budget, 'USD')]) : __(':amount left', ['amount' => $money($budget - $usd, 'USD')])) : null" />
        <x-signal.ui.stat :label="__('Idle servers')" :value="$report['idle']" :description="__('No websites, or under 10% CPU for the last hour')" />
    </div>
    @if ($budget !== null && $usd > $budget)
        <x-signal.ui.alert tone="warning">{{ __('Servers priced in US dollars cost more than the monthly budget.') }}</x-signal.ui.alert>
    @endif

    @if ($report['rows']->isEmpty())
        <x-signal.ui.empty-state icon="server" :title="__('No servers yet')" :description="__('Costs appear once the account has servers.')" />
    @else
        <x-signal.ui.table :caption="__('Server costs')">
            <x-slot:head><tr><th scope="col">{{ __('Server') }}</th><th scope="col">{{ __('Size') }}</th><th scope="col">{{ __('Used by') }}</th><th scope="col">{{ __('CPU (1 h)') }}</th><th scope="col" class="text-right">{{ __('Per month') }}</th></tr></x-slot:head>
            @foreach ($report['rows'] as $row)
                <tr>
                    <td>
                        <a href="{{ route('infrastructure.servers.show', [$project, $row->server->id]) }}" class="font-bold text-primary hover:underline">{{ $row->server->label() }}</a>
                        @if ($row->idle)<x-signal.ui.badge tone="warning">{{ __('Idle') }}</x-signal.ui.badge>@endif
                    </td>
                    <td class="text-muted">{{ $row->server->provider?->type->label() ?? __('Imported') }}@if ($row->server->size) · <span class="font-mono text-xs">{{ $row->server->size }}</span>@endif</td>
                    <td class="text-muted">
                        {{ trans_choice(':count website|:count websites', $row->websites) }}
                        · {{ match ($row->attribution()) { 'direct' => $row->projects[0], 'shared' => __('Shared: :projects', ['projects' => implode(', ', $row->projects)]), default => __('No project') } }}
                    </td>
                    <td>{{ $row->averageCpu === null ? '—' : $row->averageCpu.'%' }}</td>
                    <td class="text-right font-bold">{{ $row->monthly === null ? '—' : $money($row->monthly, $row->server->monthly_cost_currency ?? 'USD') }}@if ($row->server->monthly_cost_source === 'manual') <span class="text-xs font-normal text-muted">{{ __('entered') }}</span>@endif</td>
                </tr>
            @endforeach
        </x-signal.ui.table>
        <p class="text-xs text-muted">{{ __('Prices are list prices from each provider (Hetzner in euros, including VAT), checked daily; bandwidth, backups and taxes aren’t included.') }}</p>
    @endif

    @if ($bills !== [])
        <x-signal.ui.settings-section id="bills" :title="__('Actual bills')" :description="__('What each provider charged, read daily from its billing API, next to the estimate from list prices. Hetzner has no billing API, so it isn’t shown.')">
            <x-signal.ui.table :caption="__('Actual bills')" :framed="false">
                <x-slot:head><tr><th scope="col">{{ __('Provider') }}</th><th scope="col" class="text-right">{{ __('Estimate a month') }}</th><th scope="col" class="text-right">{{ __('Last month’s invoice') }}</th><th scope="col" class="text-right">{{ __('Difference') }}</th><th scope="col" class="text-right">{{ __('This month so far') }}</th></tr></x-slot:head>
                @foreach ($bills as $row)
                    <tr>
                        <td>
                            <span class="font-bold text-ink">{{ $row['provider']->name }}</span> <span class="text-muted">· {{ $row['provider']->type->label() }}</span>
                            @if ($row['provider']->billing_error)<p class="text-xs text-danger">{{ $row['provider']->billing_error }}</p>@elseif ($row['provider']->billing_checked_at === null)<p class="text-xs text-muted">{{ __('Not read yet') }}</p>@endif
                        </td>
                        <td class="text-right tabular-nums">{{ $row['estimate'] === null ? '—' : $money($row['estimate'], 'USD') }}</td>
                        <td class="text-right tabular-nums">{{ $row['previous'] === null ? '—' : $money($row['previous']->amount, $row['previous']->currency) }}</td>
                        <td class="text-right tabular-nums">@if ($row['difference'] === null)—@elseif (abs($row['difference']) < 0.01){{ __('Matches') }}@else<x-signal.ui.badge :tone="$row['difference'] > 0 ? 'warning' : 'success'">{{ $row['difference'] > 0 ? __(':amount more', ['amount' => $money($row['difference'], 'USD')]) : __(':amount less', ['amount' => $money(-$row['difference'], 'USD')]) }}</x-signal.ui.badge>@endif</td>
                        <td class="text-right tabular-nums">{{ $row['current'] === null ? '—' : $money($row['current']->amount, $row['current']->currency) }}</td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
            <p class="px-4 pb-4 text-xs text-muted sm:px-6">{{ __('Invoices include bandwidth, backups, snapshots, volumes and anything else in the provider account, so they’re usually higher than the servers alone. Lightsail and EC2 read AWS Cost Explorer, which needs ce:GetCostAndUsage on the key.') }}</p>
        </x-signal.ui.settings-section>
    @endif

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Prices')" :description="__('Ask the providers for current prices now, or enter what an imported server costs.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <form method="POST" action="{{ route('infrastructure.costs.refresh', $project) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Check prices and bills now') }}</x-signal.ui.button></form>
                @foreach ($report['rows']->filter(fn ($row) => $row->server->provider_id === null) as $row)
                    <form method="POST" action="{{ route('infrastructure.costs.servers.update', [$project, $row->server->id]) }}" class="grid items-end gap-3 sm:grid-cols-[1fr_10rem_7rem_auto]">
                        @csrf
                        @method('PUT')
                        <p class="text-sm font-bold text-ink">{{ $row->server->label() }}</p>
                        <x-signal.ui.input-field :id="'cost-'.$row->server->id" name="monthly_cost" type="number" step="0.01" min="0" :label="__('Per month')" :value="$row->server->monthly_cost" :restore="false" />
                        <x-signal.ui.select-field :id="'currency-'.$row->server->id" name="monthly_cost_currency" :label="__('Currency')">
                            @foreach (['USD', 'EUR'] as $currency)
                                <option value="{{ $currency }}" @selected(($row->server->monthly_cost_currency ?? 'USD') === $currency)>{{ $currency }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Save') }}</x-signal.ui.button>
                    </form>
                @endforeach
            </div>
        </x-signal.ui.settings-section>
    @endif

    @if ($rightsizing !== [])
        <x-signal.ui.settings-section id="rightsizing" :title="__('Right-size your servers')" :description="__('From the last :days days of CPU and memory: the load the busiest 5% of the time needs, with headroom to spare. Resize in your provider’s dashboard; plan for a short restart.', ['days' => \App\Services\Infrastructure\ServerRightsizing::DAYS])">
            <x-signal.ui.table :caption="__('Suggested sizes')" :framed="false">
                <x-slot:head><tr><th scope="col">{{ __('Server') }}</th><th scope="col">{{ __('Busiest 5%') }}</th><th scope="col">{{ __('Now') }}</th><th scope="col">{{ __('Suggested') }}</th><th scope="col" class="text-right">{{ __('Per month') }}</th></tr></x-slot:head>
                @foreach ($rightsizing as $row)
                    <tr>
                        <td><a class="font-bold text-primary hover:underline" href="{{ route('infrastructure.servers.show', [$project, $row['server']->id]) }}">{{ $row['server']->name }}</a></td>
                        <td class="text-sm">{{ __('CPU :cpu%, memory :memory%', ['cpu' => $row['cpu'], 'memory' => $row['memory']]) }}</td>
                        <td class="text-sm"><span class="font-mono">{{ $row['current']['id'] }}</span> <span class="text-muted">· {{ __(':cpu vCPU, :memory GB', ['cpu' => $row['current']['vcpus'], 'memory' => $row['current']['memory_gb']]) }}</span></td>
                        <td class="text-sm"><x-signal.ui.badge :tone="$row['direction'] === 'up' ? 'warning' : 'success'">{{ $row['direction'] === 'up' ? __('Bigger') : __('Smaller') }}</x-signal.ui.badge> <span class="font-mono">{{ $row['suggested']['id'] }}</span> <span class="text-muted">· {{ __(':cpu vCPU, :memory GB', ['cpu' => $row['suggested']['vcpus'], 'memory' => $row['suggested']['memory_gb']]) }}</span></td>
                        <td class="text-right tabular-nums">{{ $row['saving'] === null ? '—' : ($row['saving'] >= 0 ? __('saves :amount', ['amount' => number_format($row['saving'], 2)]) : __('costs :amount more', ['amount' => number_format(-$row['saving'], 2)])) }}</td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        </x-signal.ui.settings-section>
    @endif

    @if ($canBudget)
        <x-signal.ui.settings-section :title="__('Monthly budget')" :description="__('In US dollars, compared with the servers priced in dollars. Leave it empty for no budget.')">
            <form method="POST" action="{{ route('infrastructure.costs.budget', $project) }}" class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                @csrf
                @method('PUT')
                <x-signal.ui.input-field id="budget" name="monthly_infrastructure_budget" type="number" step="0.01" min="0" :label="__('Budget (USD)')" :value="$budget" />
                <x-signal.ui.button type="submit" variant="primary">{{ __('Save budget') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.settings-section>
    @elseif ($canManage)
        <x-signal.ui.alert tone="info">{{ __('Budgets come with the Pro Deploy plan and above.') }}</x-signal.ui.alert>
    @endif
</x-signal.layouts.project>
