@props(['tone' => 'info', 'role' => null])

@php($signalTone = $tone === 'primary' ? 'info' : $tone)

<x-signal.ui.alert :tone="$signalTone" :role="$role" {{ $attributes->class(['block']) }}>{{ $slot }}</x-signal.ui.alert>
