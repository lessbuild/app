@props(['id', 'label', 'errorKey' => null, 'description' => null, 'hideLabel' => false])

<div {{ $attributes->class(['min-w-0']) }}>
    <label for="{{ $id }}" @class(['ui-label', 'sr-only' => $hideLabel, 'wrap-anywhere' => ! $hideLabel])>{{ $label }}</label>
    {{ $slot }}
    @if($description !== null)<p id="{{ $id }}-help" class="ui-help wrap-anywhere">{{ $description }}</p>@endif
    @if($errorKey !== null)
        @error($errorKey)<p id="{{ $id }}-error" class="ui-error wrap-anywhere">{{ $message }}</p>@enderror
    @endif
</div>
