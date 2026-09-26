<x-signal.layouts.core
    :title="__('Help and guides') . ' · Buildpusher'"
    :description="__('Guides and API references for Buildpusher Deployer, Monitor, Analytics, and shared workspaces.')"
    :canonical="route('core.help')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-screen-2xl px-5 py-12 sm:px-8 sm:py-16">
        <x-signal.ui.page-header
            eyebrow="{{ __('Buildpusher support') }}"
            :title="__('Help and guides')"
            :description="__('Find product guides, API references, and workspace support in one place.')"
        >
            <x-slot:actions>
                <x-signal.ui.button :href="route('core.entry')" variant="secondary">{{ __('Buildpusher home') }}</x-signal.ui.button>
                @if (auth('platform')->check())
                    <x-signal.ui.button :href="route('core.home')" variant="primary">{{ __('Open workspace') }}</x-signal.ui.button>
                @else
                    <x-signal.ui.button :href="route('platform.login')" variant="primary">{{ __('Sign in') }}</x-signal.ui.button>
                @endif
            </x-slot:actions>
        </x-signal.ui.page-header>

        <section class="mt-8 grid gap-5 lg:grid-cols-3" aria-label="{{ __('Product documentation') }}">
            @foreach (['deployer' => __('Deployer'), 'monitor' => __('Monitor'), 'analytics' => __('Analytics')] as $product => $label)
                @php($productDocuments = $documents->get($product, collect()))
                <x-signal.ui.card class="flex h-full flex-col p-5 sm:p-6">
                    <div>
                        <p class="ui-eyebrow">{{ __('Product guide') }}</p>
                        <h2 class="mt-2 text-xl font-extrabold text-ink">{{ $label }}</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">
                            @switch($product)
                                @case('deployer') {{ __('Deploy applications, manage infrastructure, and automate releases.') }} @break
                                @case('monitor') {{ __('Collect telemetry, investigate incidents, and manage service reliability.') }} @break
                                @default {{ __('Measure website traffic, configure goals, and share analytics reports.') }}
                            @endswitch
                        </p>
                    </div>

                    @if ($productDocuments->isNotEmpty())
                        <ul class="mt-5 grid gap-2 border-t border-line pt-4">
                            @foreach ($productDocuments as $document)
                                <li>
                                    <a href="{{ $document['href'] }}" class="group flex min-h-12 items-center justify-between gap-3 rounded-control px-3 py-2 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-bold text-ink">{{ $document['label'] }}</span>
                                            <span class="mt-0.5 block text-xs leading-5 text-muted">{{ $document['description'] }}</span>
                                        </span>
                                        <span aria-hidden="true" class="shrink-0 text-primary transition-transform group-hover:translate-x-0.5">↗</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="mt-5 flex-1 border-t border-line pt-4">
                            <p class="text-sm text-muted">{{ __('Product documentation is being organized for this app.') }}</p>
                            <x-signal.ui.button :href="route('core.marketing.product', $product)" variant="quiet" class="mt-3">{{ __('View :product overview', ['product' => $label]) }}</x-signal.ui.button>
                        </div>
                    @endif
                </x-signal.ui.card>
            @endforeach
        </section>

        <x-signal.ui.card class="mt-6 flex flex-wrap items-center justify-between gap-4 p-5 sm:p-6">
            <div>
                <h2 class="font-extrabold text-ink">{{ __('Workspace support') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Use the workspace dashboard to manage projects, app access, plans, and feedback.') }}</p>
            </div>
            @if (auth('platform')->check())
                <x-signal.ui.button :href="route('core.home')" variant="primary">{{ __('Open your workspace') }}</x-signal.ui.button>
            @else
                <x-signal.ui.button :href="route('platform.login')" variant="primary">{{ __('Sign in to your workspace') }}</x-signal.ui.button>
            @endif
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
