@props([
    'navigation' => [],
    'searchUrl' => null,
    'searchActionUrl' => null,
    'extraItems' => [],
])

@php
    $items = collect($navigation['groups'] ?? [])
        ->flatMap(fn (array $group): array => $group['items'] ?? [])
        ->filter(fn (array $item): bool => filled($item['href'] ?? null) || filled($item['route'] ?? null));
    $productItems = collect(config('platform.products', []))
        ->map(function (array $product, string $key): ?array {
            $routeName = $key === 'deployer' ? 'dashboard' : $key.'.dashboard';

            if (\Illuminate\Support\Facades\Route::has($routeName)) {
                return ['label' => $product['label'] ?? ucfirst($key), 'href' => route($routeName)];
            }

            return filled($product['url'] ?? null)
                ? ['label' => $product['label'] ?? ucfirst($key), 'href' => $product['url']]
                : null;
        })
        ->filter();
    $extraItems = collect($extraItems)
        ->filter(fn (array $item): bool => filled($item['label'] ?? null) && filled($item['href'] ?? null))
        ->values();
@endphp

<dialog id="signal-command-palette" data-signal-command-palette data-modal-sheet @if($searchUrl) data-signal-command-search-url="{{ $searchUrl }}" @endif class="ui-dialog ui-command-dialog max-h-[80vh] overflow-hidden" aria-labelledby="signal-command-title" aria-modal="true">
    <div class="p-5 sm:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow">{{ __('Quick navigation') }}</p>
                <h2 id="signal-command-title" class="mt-2 text-xl font-extrabold text-ink">{{ __('Search this workspace') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Jump to a product, report, or workspace action.') }}</p>
            </div>
            <form method="dialog">
                <x-signal.ui.icon-button type="submit" label="{{ __('Close quick navigation') }}">
                    <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
                </x-signal.ui.icon-button>
            </form>
        </div>
        @if ($searchActionUrl)
            <form method="GET" action="{{ $searchActionUrl }}">
        @endif
        <label for="signal-command-query" class="sr-only">{{ __('Search navigation and workspace resources') }}</label>
        <x-signal.ui.input
            id="signal-command-query"
            :name="$searchActionUrl ? 'q' : null"
            data-signal-command-input
            type="search"
            maxlength="100"
            class="mt-6 text-base"
            autocomplete="off"
            placeholder="{{ __('Search pages, projects, servers, deployments, incidents, and sites…') }}"
        />
        @if ($searchActionUrl)
            </form>
        @endif
        <nav data-signal-command-results class="ui-command-list mt-4 max-h-[min(28rem,55vh)] overflow-y-auto" aria-label="Quick actions">
            @if ($extraItems->isNotEmpty())
                <section class="ui-command-section" data-signal-command-section>
                    <p class="ui-command-heading">{{ __('Quick actions') }}</p>
                    @foreach ($extraItems as $item)
                        <div class="ui-command-row" data-signal-command-row>
                            <a
                                href="{{ $item['href'] }}"
                                data-signal-command-item
                                data-search="{{ strtolower(($item['label'] ?? '').' '.($item['keywords'] ?? '')) }}"
                                @if ($modal = ($item['modal'] ?? null))
                                    data-modal-trigger="{{ $modal }}"
                                    aria-controls="{{ $modal }}"
                                    aria-expanded="{{ ($item['modalOpen'] ?? false) ? 'true' : 'false' }}"
                                    @if (filled($item['modalUrl'] ?? null)) data-modal-content-url="{{ $item['modalUrl'] }}" @endif
                                @endif
                                class="ui-command-item"
                            >
                                <span>{{ $item['label'] }}</span>
                                <span class="ui-command-meta" aria-hidden="true">↵</span>
                            </a>
                        </div>
                    @endforeach
                </section>
            @endif
            @if ($productItems->isNotEmpty())
                <section class="ui-command-section" data-signal-command-section>
                    <p class="ui-command-heading">{{ __('Products') }}</p>
                    @foreach ($productItems as $item)
                        <div class="ui-command-row" data-signal-command-row>
                            <a href="{{ $item['href'] }}" data-signal-command-item data-search="{{ strtolower($item['label']) }}" class="ui-command-item">
                                <span>{{ $item['label'] }}</span>
                                <span class="ui-command-meta">{{ __('Open') }}</span>
                            </a>
                        </div>
                    @endforeach
                </section>
            @endif
            @if ($items->isNotEmpty())
                <section class="ui-command-section" data-signal-command-section>
                    <p class="ui-command-heading">{{ __('Product sections') }}</p>
                    @foreach ($items as $item)
                        @php
                            $href = $item['href'] ?? route($item['route']);
                            $search = strtolower(($item['label'] ?? '').' '.($item['keywords'] ?? ''));
                        @endphp
                        <div class="ui-command-row" data-signal-command-row>
                            <a href="{{ $href }}" data-signal-command-item data-search="{{ $search }}" class="ui-command-item">
                                <span class="flex min-w-0 items-center gap-2">
                                    @if (! empty($item['icon']))
                                        <svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $item['icon'] }}"></use></svg>
                                    @endif
                                    <span class="truncate">{{ $item['label'] }}</span>
                                </span>
                                <span class="ui-command-meta">{{ __('Open') }}</span>
                            </a>
                        </div>
                    @endforeach
                </section>
            @endif
            <div data-signal-command-dynamic-results class="contents" aria-label="Workspace resource results"></div>
        </nav>
        <p data-signal-command-empty hidden class="px-3 py-6 text-center text-sm text-muted">{{ __('No matching pages or actions.') }}</p>
        <p data-signal-command-status class="sr-only" role="status" aria-live="polite"></p>
        <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-[10px] font-bold uppercase tracking-wide text-subtle"><span>{{ __('Signal quick navigation') }}</span><kbd class="ui-kbd">Esc</kbd></div>
    </div>
</dialog>
