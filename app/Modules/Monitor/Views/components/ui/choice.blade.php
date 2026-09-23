@props(['id', 'name', 'label', 'value' => 1, 'checked' => false, 'type' => 'checkbox', 'description' => null, 'card' => false, 'errorKey' => null])
@php
    $validationKey = $errorKey === false ? null : ($errorKey ?? $name);
    $hasError = $validationKey !== null && $errors->has($validationKey);
    $controlType = $type === 'radio' ? 'radio' : 'checkbox';
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$id.'-help' : '').($hasError ? ' '.$id.'-error' : ''));
@endphp
<div class="min-w-0">
    <label for="{{ $id }}" @class(['ui-choice' => $card, 'flex cursor-pointer items-start gap-3' => !$card])>
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $controlType }}" value="{{ $value }}"
            @checked(in_array($checked, [true, 1, '1'], true))
            aria-invalid="{{ $hasError ? 'true' : 'false' }}"
            @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['aria-describedby', 'aria-invalid'])->class(['ui-check mt-0.5 shrink-0']) }}>
        <span class="min-w-0 flex-1 wrap-anywhere">
            <span class="block text-sm font-semibold text-ink">{{ $label }}</span>
            @if($description !== null)<span id="{{ $id }}-help" class="ui-help block">{{ $description }}</span>@endif
        </span>
        {{ $slot }}
    </label>
    @if($validationKey !== null)
        @error($validationKey)<p id="{{ $id }}-error" class="ui-error">{{ $message }}</p>@enderror
    @endif
</div>
