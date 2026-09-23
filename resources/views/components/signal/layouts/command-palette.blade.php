@props(['navigation' => []])

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
@endphp

<dialog id="signal-command-palette" data-signal-command-palette class="ui-dialog ui-command-dialog max-h-[80vh] overflow-hidden" aria-labelledby="signal-command-title" aria-modal="true">
    <div class="p-5 sm:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow">Quick navigation</p>
                <h2 id="signal-command-title" class="mt-2 text-xl font-extrabold text-ink">Search this workspace</h2>
                <p class="mt-2 text-sm leading-6 text-muted">Jump to a product, report, or workspace action.</p>
            </div>
            <form method="dialog">
                <button type="submit" class="ui-icon-btn" aria-label="Close quick navigation">
                    <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
                </button>
            </form>
        </div>
        <label for="signal-command-query" class="sr-only">Search navigation</label>
        <input id="signal-command-query" data-signal-command-input type="search" class="ui-input mt-6 text-base" autocomplete="off" placeholder="Search pages and actions…">
        <nav data-signal-command-results class="mt-4 grid max-h-[min(28rem,55vh)] gap-1 overflow-y-auto" aria-label="Quick actions">
            <p class="px-3 py-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">Products</p>
            @foreach ($productItems as $item)
                <a href="{{ $item['href'] }}" data-signal-command-item data-search="{{ strtolower($item['label']) }}" class="flex min-h-11 items-center justify-between rounded-control px-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">
                    <span>{{ $item['label'] }}</span>
                    <svg class="h-4 w-4 stroke-2 text-subtle" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-up-right"></use></svg>
                </a>
            @endforeach
            @foreach ($items as $item)
                @php
                    $href = $item['href'] ?? route($item['route']);
                    $search = strtolower(($item['label'] ?? '').' '.($item['keywords'] ?? ''));
                @endphp
                <a href="{{ $href }}" data-signal-command-item data-search="{{ $search }}" class="flex min-h-11 items-center gap-2 rounded-control px-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">
                    @if (! empty($item['icon']))
                        <svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $item['icon'] }}"></use></svg>
                    @endif
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
        <p data-signal-command-empty hidden class="px-3 py-6 text-center text-sm text-muted">No matching pages or actions.</p>
        <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-[10px] font-bold uppercase tracking-wide text-subtle"><span>Signal quick navigation</span><kbd class="ui-kbd">Esc</kbd></div>
    </div>
</dialog>
