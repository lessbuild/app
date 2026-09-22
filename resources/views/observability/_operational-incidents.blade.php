@php
    $activeOperationalIncidents = $operationalIncidents
        ->reject(fn ($incident) => $incident->status === \App\Models\OperationalIncident::STATUS_RESOLVED)
        ->values();
    $resolvedOperationalIncidents = $operationalIncidents
        ->filter(fn ($incident) => $incident->status === \App\Models\OperationalIncident::STATUS_RESOLVED)
        ->values();
@endphp

<section class="ui-panel mt-8 p-5 sm:p-6" id="operational-incidents" data-observability-section>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="ui-eyebrow">{{ __('Private response') }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h2 class="text-xl font-extrabold text-ink">{{ __('Operational incidents') }}</h2>
                @if ($activeOperationalIncidents->isNotEmpty())
                    <x-ui.badge tone="danger">{{ trans_choice(':count active|:count active', $activeOperationalIncidents->count(), ['count' => $activeOperationalIncidents->count()]) }}</x-ui.badge>
                @endif
                @if ($resolvedOperationalIncidents->isNotEmpty())
                    <x-ui.badge>{{ trans_choice(':count resolved|:count resolved', $resolvedOperationalIncidents->count(), ['count' => $resolvedOperationalIncidents->count()]) }}</x-ui.badge>
                @endif
            </div>
            <p class="mt-1 max-w-3xl text-sm leading-6 text-muted">{{ __('Failures are grouped by resource, assigned to a responder, and closed automatically when recovery is detected.') }}</p>
        </div>
        @if ($canExportIncidents)
            <x-ui.button :href="route('observability.operational-incidents.export')" variant="secondary">{{ __('Export evidence') }}</x-ui.button>
        @endif
    </div>

    <div id="operational-incident-active" class="ui-inventory-list mt-5 space-y-3" aria-label="{{ __('Active operational incidents') }}">
        @forelse ($activeOperationalIncidents as $incident)
            @include('observability._operational-incident-card', ['incident' => $incident, 'openDetails' => false])
        @empty
            @if ($resolvedOperationalIncidents->isEmpty())
                <x-ui.empty-state
                    :title="__('No operational incidents')"
                    :description="__('New failures will appear here automatically.')"
                    icon="warning"
                />
            @else
                <p class="rounded-card border border-line bg-surface-muted p-4 text-sm text-muted">{{ __('No active operational incidents.') }}</p>
            @endif
        @endforelse
    </div>

    @if ($resolvedOperationalIncidents->isNotEmpty())
        <details id="operational-incident-history" class="ui-panel mt-5 bg-surface-muted p-4">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-md font-bold text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                <span>{{ __('Resolved incident history') }}</span>
                <span class="flex items-center gap-2">
                    <x-ui.badge>{{ $resolvedOperationalIncidents->count() }}</x-ui.badge>
                    <span class="text-muted" aria-hidden="true">⌄</span>
                </span>
            </summary>
            <div class="mt-3 space-y-3">
                @foreach ($resolvedOperationalIncidents as $incident)
                    @include('observability._operational-incident-card', ['incident' => $incident, 'openDetails' => false])
                @endforeach
            </div>
        </details>
    @endif
</section>
