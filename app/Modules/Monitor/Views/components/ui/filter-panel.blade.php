@props(['action' => null, 'method' => 'GET'])

<x-monitor::ui.panel as="form" :method="$method" :action="$action" {{ $attributes->class(['flex flex-col gap-5']) }}>
    {{ $slot }}
</x-monitor::ui.panel>
