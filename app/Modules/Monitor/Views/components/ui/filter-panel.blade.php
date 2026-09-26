@props(['action' => null, 'method' => 'GET'])

<x-signal.ui.card as="form" :method="$method" :action="$action" {{ $attributes->class(['flex flex-col gap-5']) }}>
    {{ $slot }}
</x-signal.ui.card>
