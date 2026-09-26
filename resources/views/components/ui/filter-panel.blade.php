@props([
    'open' => false,
    'label' => __('Filters'),
    'summary' => null,
])

<x-signal.ui.filter-panel :open="$open" :label="$label" :summary="$summary" {{ $attributes }}>{{ $slot }}</x-signal.ui.filter-panel>
