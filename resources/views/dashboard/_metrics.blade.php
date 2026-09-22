@if(in_array('stats', $dashboardWidgets, true))
    <dl data-dashboard-stats class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Workspace totals') }}">
        @foreach ([
            [__('Websites'), $stats['websites'], __('Hosted application targets')],
            [__('Servers'), $stats['servers'], __('Provisioned compute')],
            [__('Builds'), $stats['builds'], __('Recorded releases')],
            [__('Repositories'), $stats['repositories'], __('Connected sources')],
        ] as [$label, $value, $description])
            <div class="ui-card p-4 sm:p-5">
                <dt class="text-xs font-bold text-muted">{{ $label }}</dt>
                <dd class="mt-4 text-3xl font-extrabold tracking-tight text-ink">{{ $value }}</dd>
                <dd class="mt-2 text-xs text-muted">{{ $description }}</dd>
            </div>
        @endforeach
    </dl>
@endif
