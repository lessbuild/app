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

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Prices')" :description="__('Ask the providers for current prices now, or enter what an imported server costs.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <form method="POST" action="{{ route('infrastructure.costs.refresh', $project) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Check prices now') }}</x-signal.ui.button></form>
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
