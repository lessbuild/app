<x-signal.layouts.base :title="__(':page uptime in :month', ['page' => $page->name, 'month' => $report['label']])" :description="__('Uptime and incidents for :month.', ['month' => $report['label']])" indexable>
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-4xl gap-6 px-4 py-10 sm:px-8 sm:py-14">
        <header>
            <p class="ui-eyebrow"><a href="{{ $page->publicUrl() }}" class="hover:underline">{{ $page->name }}</a></p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Uptime in :month', ['month' => $report['label']]) }}</h1>
            <p class="mt-3 text-sm text-muted">
                {{ $report['uptime'] === null ? __('Not enough data yet.') : __(':uptime% overall', ['uptime' => number_format($report['uptime'], 3)]) }}
                · {{ trans_choice(':count incident|:count incidents', count($report['incidents']), ['count' => count($report['incidents'])]) }}
                · {{ __(':minutes minutes of downtime', ['minutes' => number_format($report['downtime_minutes'])]) }}
            </p>
        </header>

        <x-signal.ui.table :caption="__('Uptime by component')">
            <x-slot:head><tr><th scope="col">{{ __('Component') }}</th><th scope="col" class="text-right">{{ __('Uptime') }}</th><th scope="col" class="text-right">{{ __('Checks') }}</th></tr></x-slot:head>
            @foreach ($report['components'] as $row)
                <tr>
                    <td class="font-bold text-ink">{{ $row['name'] }}@if ($row['group']) <span class="text-xs font-normal text-muted">· {{ $row['group'] }}</span>@endif</td>
                    <td class="text-right tabular-nums">{{ $row['uptime'] === null ? '—' : number_format($row['uptime'], 3).'%' }}</td>
                    <td class="text-right tabular-nums">{{ number_format($row['checks']) }}</td>
                </tr>
            @endforeach
        </x-signal.ui.table>

        @if ($report['incidents'] !== [])
            <x-signal.ui.card class="overflow-hidden">
                <div class="border-b border-line px-5 py-4 sm:px-6"><h2 class="font-extrabold text-ink">{{ __('Incidents') }}</h2></div>
                <ul class="divide-y divide-line">
                    @foreach ($report['incidents'] as $incident)
                        <li class="px-5 py-4 sm:px-6">
                            <p class="font-bold text-ink">{{ $incident['title'] }}</p>
                            <p class="text-xs text-muted">{{ $incident['component'] }} · {{ $incident['opened_at']->format('Y-m-d H:i') }}@if ($incident['resolved_at']) – {{ $incident['resolved_at']->format('Y-m-d H:i') }}@endif UTC · {{ trans_choice(':count minute|:count minutes', $incident['minutes'], ['count' => number_format($incident['minutes'])]) }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-signal.ui.card>
        @endif

        <nav aria-label="{{ __('Other months') }}" class="flex flex-wrap gap-2">
            @foreach ($months as $month)
                <x-signal.ui.button :href="route('status.month', [$page->slug, $month->format('Y-m')])" size="sm" :variant="$month->format('Y-m') === $report['month'] ? 'soft' : 'quiet'" :aria-current="$month->format('Y-m') === $report['month'] ? 'page' : null">{{ $month->isoFormat('MMM YYYY') }}</x-signal.ui.button>
            @endforeach
        </nav>
    </main>
</x-signal.layouts.base>
