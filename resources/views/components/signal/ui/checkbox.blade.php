@props([
    'id' => null,
    'name',
    'value' => '1',
    'checked' => false,
    'uncheckedValue' => null,
    'description' => null,
    'errorKey' => null,
    'required' => false,
    'restore' => true,
    'showErrors' => true,
    'containerClass' => null,
    'labelClass' => null,
])

@php($id = $id ?: $name)
@php($checked = $restore ? old($name, $checked) : $checked)
@php($validationKey = $errorKey === false ? null : ($errorKey ?? $name))
@php($hasError = $validationKey !== null && $errors->has($validationKey))
@php($invalid = $hasError || $attributes->get('aria-invalid') === 'true')
@php($describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$id.'-help' : '').($hasError ? ' '.$id.'-error' : '')))

<div @class([$containerClass ?: 'grid gap-2'])>
@if ($uncheckedValue !== null)
    <input type="hidden" name="{{ $name }}" value="{{ $uncheckedValue }}">
@endif
<label @class(['inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-ink', $labelClass])>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="checkbox"
        value="{{ $value }}"
        @checked($checked)
        @required($required)
        aria-invalid="{{ $invalid ? 'true' : 'false' }}"
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except(['aria-describedby', 'aria-invalid', 'required'])->class(['h-4 w-4 rounded border-line accent-[var(--ui-primary)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus']) }}
    >
    <span>{{ $slot }}</span>
</label>
@if ($description)
    <p id="{{ $id }}-help" class="ui-help">{{ $description }}</p>
@endif
@if ($showErrors && is_string($validationKey) && $validationKey !== '')
    <x-forms.errors :name="$validationKey" :id="$id.'-error'" />
@endif
</div>
