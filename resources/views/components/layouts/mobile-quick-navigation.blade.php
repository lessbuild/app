@props([
    'createUrl',
    'createOpen' => false,
])

<x-signal.layouts.mobile-quick-navigation :create-url="$createUrl" :create-open="$createOpen" {{ $attributes }} />
