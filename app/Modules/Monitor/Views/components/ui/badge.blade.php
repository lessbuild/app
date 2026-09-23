@props(['tone' => 'slate'])

@php
    $tones = [
        'slate' => 'ui-badge-soft',
        'soft' => 'ui-badge-soft',
        'primary' => 'ui-badge-primary',
        'violet' => 'ui-badge-primary',
        'sky' => 'ui-badge-info',
        'green' => 'ui-badge-success',
        'amber' => 'ui-badge-warning',
        'red' => 'ui-badge-danger',
        'emerald' => 'ui-badge-success',
        'success' => 'ui-badge-success',
        'warning' => 'ui-badge-warning',
        'danger' => 'ui-badge-danger',
        'info' => 'ui-badge-info',
    ];
@endphp

<span {{ $attributes->class(['ui-badge', $tones[$tone] ?? $tones['slate']]) }}>
    {{ $slot }}
</span>
