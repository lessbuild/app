@props(['projectName', 'apps' => []])

<x-signal.ui.card as="figure" {{ $attributes->class(['product-connections p-5 sm:p-6']) }}>
    <figcaption class="text-center">
        <p class="ui-eyebrow">{{ __('Connected by project') }}</p>
        <p class="mt-1 text-sm font-extrabold text-ink">{{ $projectName }}</p>
        <p class="mt-1 text-[0.65rem] font-semibold text-subtle">{{ __('Illustrative project') }}</p>
    </figcaption>

    <div class="mt-5 flex flex-col items-center">
        <span class="product-connection-root font-extrabold text-ink">
            <x-signal.ui.icon name="grid" class="size-4 text-primary" />
            {{ __('Shared project') }}
        </span>
        <span class="product-connection-stem" aria-hidden="true"></span>

        <nav class="product-connection-branches w-full" aria-label="{{ __('Apps connected to :project', ['project' => $projectName]) }}">
            @foreach ($apps as $app)
                @php($accent = in_array($app['accent'] ?? null, ['deploy', 'monitor', 'analytics'], true) ? $app['accent'] : 'deploy')
                <a class="product-connection-app product-accent-{{ $accent }} product-preview-{{ $accent }} px-1.5 py-2 transition hover:border-[var(--product-preview-accent)] hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" href="{{ $app['href'] }}">
                    <x-signal.ui.icon :name="$app['icon']" class="size-3.5 shrink-0" />
                    <span class="truncate">{{ $app['name'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>

    <p class="mt-5 text-center text-xs leading-5 text-muted">{{ __('Share project context while each app keeps its own data and plan.') }}</p>
</x-signal.ui.card>
