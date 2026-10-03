@php($total = max(1, array_sum($summary['eventBreakdown'])))
<ul class="mt-4 grid gap-3" aria-label="{{ __('Events by type') }}">
    @foreach ($summary['eventBreakdown'] as $type => $count)
        <li class="grid gap-1">
            <div class="flex justify-between text-sm"><span class="font-bold text-ink">{{ __(ucfirst($type)) }}</span><span class="tabular-nums text-muted">{{ number_format($count) }}</span></div>
            <div class="h-2 rounded-full bg-surface-muted"><div class="h-2 rounded-full bg-chart-1" style="width: {{ $count > 0 ? max(1, $count / $total * 100) : 0 }}%"></div></div>
        </li>
    @endforeach
</ul>
