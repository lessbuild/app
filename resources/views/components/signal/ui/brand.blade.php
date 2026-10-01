@props(['branding'])

{{-- A client-facing page's white-label mark: the agency's logo (or initial) and name. --}}
<span {{ $attributes->class(['inline-flex items-center gap-3']) }}>
    @if ($branding['logo'])
        <img src="{{ $branding['logo'] }}" alt="" class="h-10 w-auto max-w-40 object-contain" referrerpolicy="no-referrer">
    @else
        <span class="grid h-10 w-10 place-items-center rounded-card bg-primary text-sm font-extrabold text-on-primary" aria-hidden="true">{{ mb_strtoupper(mb_substr($branding['name'], 0, 1)) }}</span>
    @endif
    <span class="sr-only">{{ $branding['name'] }}</span>
</span>
