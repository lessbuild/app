<x-layouts.core
    :title="__('Unexpected error')"
    :description="__('The request could not be completed.')"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">
        {{ __('Skip to main content') }}
    </a>

    <main id="main-content" tabindex="-1" class="mx-auto flex min-h-screen w-full max-w-content items-center px-5 py-12 sm:px-8 sm:py-16">
        <section class="ui-panel w-full max-w-2xl p-6 sm:p-8" aria-labelledby="error-title">
            <p class="ui-eyebrow">{{ config('app.name') }} · {{ __('Error 500') }}</p>
            <h1 id="error-title" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">
                {{ __('Something went wrong') }}
            </h1>
            <p class="mt-4 max-w-xl text-base leading-7 text-muted">
                {{ __('The request could not be completed. You can try again, return to the dashboard, or give the reference below to the operator if the problem continues.') }}
            </p>

            <div class="ui-alert ui-alert-danger mt-6" role="alert">
                <svg class="mt-0.5 h-5 w-5 shrink-0 stroke-2" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#information-circle"></use>
                </svg>
                <p class="min-w-0 break-words text-sm">
                    <strong>{{ __('Reference:') }}</strong>
                    <code class="ml-1 font-code">{{ $incidentId ?? __('Unavailable') }}</code>
                </p>
            </div>

            <nav class="mt-6 flex flex-wrap gap-3" aria-label="{{ __('Error recovery') }}">
                <a href="{{ url()->current() }}" class="ui-btn ui-btn-primary">{{ __('Try again') }}</a>
                <a href="{{ url('/home') }}" class="ui-btn ui-btn-secondary">{{ __('Go to dashboard') }}</a>
                <a href="{{ url('/') }}" class="ui-btn ui-btn-quiet">{{ __('Go to homepage') }}</a>
            </nav>
        </section>
    </main>
</x-layouts.core>
