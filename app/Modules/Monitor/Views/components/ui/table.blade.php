@props(['caption', 'tableClass' => null, 'framed' => true])

<x-signal.ui.table :caption="$caption" :table-class="$tableClass" :framed="$framed" {{ $attributes }}>
    @isset($head)
        <x-slot:head>{{ $head }}</x-slot:head>
    @endisset
    {{ $slot }}
</x-signal.ui.table>
