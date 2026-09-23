@props(['caption', 'tableClass' => null, 'framed' => true])

<div role="region" aria-label="{{ $caption }}" tabindex="0" {{ $attributes->class(['ui-table-wrap', 'rounded-none border-0' => ! $framed]) }}>
    <table @class(['ui-table', $tableClass])>
        <caption class="sr-only">{{ $caption }}</caption>
        <thead>{{ $head }}</thead>
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
