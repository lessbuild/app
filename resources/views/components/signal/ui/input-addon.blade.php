@props([
    'position' => 'prefix',
    'tone' => 'muted',
    'decorative' => true,
])

@php($positions = ['prefix', 'suffix'])
@php($tones = ['surface', 'muted'])
@php($position = in_array($position, $positions, true) ? $position : 'prefix')
@php($tone = in_array($tone, $tones, true) ? $tone : 'muted')

<span @if ($decorative) aria-hidden="true" @endif {{ $attributes->class([
    'inline-flex min-h-10 shrink-0 items-center border border-line px-3 text-sm text-muted',
    'bg-surface' => $tone === 'surface',
    'bg-surface-muted' => $tone === 'muted',
    'rounded-control rounded-r-none border-r-0' => $position === 'prefix',
    'rounded-control rounded-l-none border-l-0' => $position === 'suffix',
]) }}>{{ $slot }}</span>
