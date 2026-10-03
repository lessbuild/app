@php($money = fn (array $amounts): string => $amounts === [] ? '—' : collect($amounts)->map(fn ($amount, $currency) => \Illuminate\Support\Number::currency($amount, $currency))->implode(' + '))

<x-signal.layouts.account :account="$account" :title="__(':client: :month', ['client' => $client->name, 'month' => $report['label']])" :description="__('What :client receives on the 1st. Costs use your current plans and server prices, with your :percent% markup.', ['client' => $client->name, 'percent' => $client->markup_percent])">
    <x-signal.ui.table :caption="__('Projects')">
        <x-slot:head><tr><th scope="col">{{ __('Project') }}</th><th scope="col" class="text-right">{{ __('Uptime') }}</th><th scope="col" class="text-right">{{ __('Incidents') }}</th><th scope="col" class="text-right">{{ __('Releases') }}</th><th scope="col" class="text-right">{{ __('Visitors') }}</th><th scope="col" class="text-right">{{ __('Cost a month') }}</th></tr></x-slot:head>
        @foreach ($report['projects'] as $project)
            <tr>
                <td class="font-bold text-ink">{{ $project['name'] }}</td>
                <td class="text-right tabular-nums">{{ $project['uptime'] === null ? '—' : number_format($project['uptime'], 2).'%' }}</td>
                <td class="text-right tabular-nums">{{ $project['incidents'] }}</td>
                <td class="text-right tabular-nums">{{ $project['deploys'] }}</td>
                <td class="text-right tabular-nums">{{ $project['visitors'] === null ? '—' : number_format($project['visitors']) }}</td>
                <td class="text-right tabular-nums">{{ $money($project['cost']) }}</td>
            </tr>
        @endforeach
        <tr><td class="font-bold text-ink">{{ __('Total') }}</td><td colspan="4"></td><td class="text-right font-bold tabular-nums">{{ $money($report['total']) }}</td></tr>
    </x-signal.ui.table>
</x-signal.layouts.account>
