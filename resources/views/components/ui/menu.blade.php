@props([
    'align' => 'left',
    'triggerClass' => '',
    'panelClass' => '',
])

<x-signal.ui.menu :align="$align" :trigger-class="$triggerClass" :panel-class="$panelClass" {{ $attributes }}>
    {{ $slot }}
    @isset($trigger)
        <x-slot:trigger>{{ $trigger }}</x-slot:trigger>
    @endisset
</x-signal.ui.menu>
