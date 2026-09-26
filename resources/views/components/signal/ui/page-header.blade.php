@props([
    'id' => null,
    'titleId' => null,
    'eyebrow' => null,
    'eyebrowIcon' => null,
    'title' => null,
    'description' => null,
    'icon' => null,
    'breadcrumbs' => [],
])

<header
    @if ($id) id="{{ $id }}" @endif
    {{ $attributes->class(['ui-page-header', 'scroll-mt-24', 'mb-7', 'flex', 'flex-col', 'gap-4', 'border-b', 'border-line', 'pb-6', 'sm:mb-8', 'sm:flex-row', 'sm:items-end', 'sm:justify-between']) }}
    data-ui-page-header
    data-page-header
>
    <div class="ui-page-header__layout min-w-0 flex-1">
        @if (is_iterable($breadcrumbs) && is_countable($breadcrumbs) && count($breadcrumbs) > 0)
            <nav aria-label="{{ __('Breadcrumb') }}" class="mb-4 min-w-0">
                <ol class="flex min-w-0 flex-wrap items-center gap-2 text-xs font-semibold text-muted">
                    @foreach ($breadcrumbs as $breadcrumb)
                        @if ($loop->first)
                            <li class="min-w-0">
                                @if (filled($breadcrumb['href'] ?? null))
                                    <a class="ui-link" href="{{ $breadcrumb['href'] }}">{{ $breadcrumb['label'] ?? '' }}</a>
                                @else
                                    <span>{{ $breadcrumb['label'] ?? '' }}</span>
                                @endif
                            </li>
                        @else
                            <li aria-hidden="true" class="text-subtle">
                                <svg class="h-[13px] w-[13px]" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                            </li>
                            <li class="min-w-0">
                                @if (filled($breadcrumb['href'] ?? null))
                                    <a class="ui-link" href="{{ $breadcrumb['href'] }}">{{ $breadcrumb['label'] ?? '' }}</a>
                                @else
                                    <span>{{ $breadcrumb['label'] ?? '' }}</span>
                                @endif
                            </li>
                        @endif
                    @endforeach
                    @if ($title)
                        <li aria-hidden="true" class="text-subtle">
                            <svg class="h-[13px] w-[13px]" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                        </li>
                        <li aria-current="page" class="min-w-0 truncate font-bold text-ink">{{ $title }}</li>
                    @endif
                </ol>
            </nav>
        @endif

        <div class="ui-page-header__identity flex min-w-0 max-w-3xl items-start gap-4">
            @if (isset($leading))
                <div class="shrink-0">{{ $leading }}</div>
            @elseif ($icon)
                <span class="ui-page-header__icon mt-2 hidden h-10 w-10 shrink-0 place-items-center rounded-card bg-primary-soft text-[var(--ui-primary)] sm:grid" aria-hidden="true">
                    <svg class="h-5 w-5 stroke-2">
                        <use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use>
                    </svg>
                </span>
            @endif
            <div class="min-w-0">
                @if ($eyebrow)
                    <p class="ui-page-header__eyebrow ui-eyebrow flex items-center gap-2">
                        @if ($eyebrowIcon)
                            <svg class="h-4 w-4 shrink-0" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $eyebrowIcon }}"></use></svg>
                        @endif
                        {{ $eyebrow }}
                    </p>
                @endif
                @if ($title)
                    <h1 @if ($titleId) id="{{ $titleId }}" @endif class="ui-page-header__title mt-1 break-words text-3xl font-extrabold tracking-tight text-ink sm:text-4xl" data-page-title>{{ $title }}</h1>
                @endif
                @if ($description)
                    <p class="ui-page-header__description mt-3 max-w-2xl text-sm leading-6 text-muted sm:text-base sm:leading-7" data-page-description>{{ $description }}</p>
                @endif
                @isset($metadata)
                    <div {{ $metadata->attributes->class(['mt-3 flex min-w-0 flex-wrap items-center gap-2']) }}>{{ $metadata }}</div>
                @endisset
            </div>
        </div>
    </div>

    @isset($actions)
        <div data-ui-page-header-actions data-page-actions class="ui-page-header__actions">
            {{ $actions }}
        </div>
    @endisset
</header>
