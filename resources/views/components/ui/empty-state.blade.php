@props([
    'title' => null,
    'description' => null,
    'icon' => 'information-circle',
])

<div data-ui-feedback="empty" {{ $attributes->merge(['class' => 'ui-empty-state']) }}>
    <div>
        <svg class="ui-empty-state__icon" aria-hidden="true">
            <use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use>
        </svg>

        @if ($title)
            <h2 class="ui-empty-state__title">{{ $title }}</h2>
        @endif
        @if ($description)
            <p class="ui-empty-state__description">{{ $description }}</p>
        @endif

        @isset($action)
            <div class="ui-empty-state__action">{{ $action }}</div>
        @endisset
    </div>
</div>
