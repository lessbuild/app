<x-layouts.core :title="$title">
    <a href="#main-content" class="ui-skip-link">
        {{ __('Skip to main content') }}
    </a>
    <div class="min-h-screen overflow-x-hidden bg-page">
        <header class="mx-auto flex w-full max-w-content items-center justify-between px-5 py-5 sm:px-8">
            <a href="{{ url('/') }}" data-auth-brand class="flex items-center gap-3 text-base font-extrabold tracking-tight text-ink" aria-label="{{ config('app.name') }} home">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface shadow-soft">
                    <img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md">
                </span>
                <span>{{ config('app.name') }}</span>
            </a>
            <button type="button" class="ui-icon-btn" data-theme-toggle aria-label="{{ __('Use dark theme') }}" aria-pressed="false">
                <svg class="h-[19px] w-[19px] stroke-2 dark:hidden" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#moon"></use></svg>
                <svg class="hidden h-[19px] w-[19px] stroke-2 dark:block" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#sun"></use></svg>
            </button>
        </header>

        <main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-content items-center px-5 py-10 sm:px-8 sm:py-16">
            <div class="grid w-full items-center gap-12 lg:grid-cols-2 lg:gap-20">
                <section class="hidden lg:block" aria-label="{{ __(':app overview', ['app' => config('app.name')]) }}">
                    <div class="ui-badge ui-badge-primary"><svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#shield"></use></svg>{{ __('A clearer place to start') }}</div>
                    <h1 class="mt-6 max-w-lg text-5xl font-extrabold leading-[1.06] tracking-[-0.045em] text-ink">{{ __('Deploy with confidence') }}</h1>
                    <p class="mt-6 max-w-md text-base leading-7 text-muted">{{ __('Provision infrastructure, release applications, and review operational history from one focused control panel.') }}</p>
                    <div class="mt-8 flex items-center gap-3 text-sm text-muted"><span class="grid h-9 w-9 place-items-center rounded-xl bg-primary-soft text-[var(--ui-primary)]"><svg class="h-[17px] w-[17px] stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#check"></use></svg></span>{{ __('Thoughtful defaults, ready to customize.') }}</div>
                </section>

                <section class="ui-panel mx-auto w-full max-w-md p-6 sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="ui-eyebrow">{{ __('Welcome back') }}</p>
                            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ $title }}</h1>
                            <div class="mt-2 leading-6 text-muted">{{ $description }}</div>
                        </div>
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-[var(--ui-primary)]"><svg class="h-[18px] w-[18px] stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#lock"></use></svg></span>
                    </div>

                    @if ($errors->any())
                        <x-ui.alert class="mt-5" tone="danger" role="alert">
                            <p class="font-bold">{{ __('Whoops! Something went wrong.') }}</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif

                    <div class="mt-6">
                        {{ $slot }}
                    </div>
                </section>
            </div>
        </main>

        <footer class="px-5 py-8 text-center text-xs text-subtle">{{ config('app.name') }} · {{ __('Your infrastructure. One focused control plane.') }}</footer>
    </div>

</x-layouts.core>
