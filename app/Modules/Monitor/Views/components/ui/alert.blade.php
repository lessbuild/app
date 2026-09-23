@props(['tone' => 'info', 'role' => null])

@php
    $tones = [
        'success' => 'ui-alert-success',
        'warning' => 'ui-alert-warning',
        'danger' => 'ui-alert-danger',
        'primary' => 'border-primary/30 bg-primary-soft',
        'info' => 'border-info/30 bg-info-soft',
    ];
@endphp

<div @if($role) role="{{ $role }}" @endif {{ $attributes->class(['ui-alert block', $tones[$tone] ?? $tones['info']]) }}>
    {{ $slot }}
</div>
