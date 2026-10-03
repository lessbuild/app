@props([
    'id',
    'name',
    'label',
    'value' => '1',
    'checked' => false,
    'type' => 'checkbox',
    'description' => null,
    'card' => false,
    'required' => false,
    'errorKey' => null,
    'showErrors' => true,
    'restore' => true,
    'uncheckedValue' => null,
])

@php($controlType = in_array($type, ['checkbox', 'radio'], true) ? $type : 'checkbox')
@php($fieldName = $errorKey === false ? null : ($errorKey ?? $name))
@php($hasError = is_string($fieldName) && $errors->has($fieldName))
@php($describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$id.'-help' : '').($hasError ? ' '.$id.'-error' : '')))

<div class="min-w-0">
    @if ($uncheckedValue !== null)
        <input type="hidden" name="{{ $name }}" value="{{ $uncheckedValue }}">
    @endif
    <label for="{{ $id }}" @class(['ui-choice' => $card, 'inline-flex min-h-10 cursor-pointer items-start gap-3 py-1' => ! $card])>
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $controlType }}"
            value="{{ $value }}"
            @checked($restore ? old($fieldName ?? $name, $checked) : $checked)
            @required($required)
            aria-invalid="{{ $hasError ? 'true' : 'false' }}"
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['aria-describedby', 'aria-invalid'])->class(['ui-check mt-0.5 shrink-0']) }}
        >
        <span class="min-w-0 flex-1 wrap-anywhere">
            <span class="block text-sm font-semibold text-ink">
                {{ $label }}
                @if ($required)<span class="text-danger" aria-hidden="true">*</span>@endif
            </span>
            @if ($description !== null)
                <span id="{{ $id }}-help" class="ui-help block">{{ $description }}</span>
            @endif
            {{ $slot }}
        </span>
    </label>
    @if ($showErrors && is_string($fieldName) && $fieldName !== '')
        <x-signal.ui.field-error :name="$fieldName" :id="$id.'-error'" />
    @endif
</div>
