@props([
    'id' => null,
    'summary' => null,
    'open' => true,
    'mobileOpen' => false,
])

<x-signal.ui.insights :id="$id" :summary="$summary" :open="$open" :mobile-open="$mobileOpen" {{ $attributes }}>{{ $slot }}</x-signal.ui.insights>
