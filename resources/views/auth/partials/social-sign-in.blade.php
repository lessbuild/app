@if ($socialProviders !== [])
    <div class="grid gap-3">
        <p class="text-center text-xs font-bold uppercase tracking-wide text-muted">{{ __('Or continue with') }}</p>
        <div @class(['grid gap-2', 'sm:grid-cols-2' => count($socialProviders) === 2, 'sm:grid-cols-3' => count($socialProviders) >= 3])>
            @foreach ($socialProviders as $provider)
                <x-signal.ui.button :href="route('social.redirect', $provider)" variant="secondary" class="w-full justify-center">{{ $provider->label() }}</x-signal.ui.button>
            @endforeach
        </div>
    </div>
@endif
