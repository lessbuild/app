@props(['action' => 'in'])

@php
    $providers = collect([
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ])->filter(fn (string $label, string $provider) =>
        filled(config("services.{$provider}.client_id"))
        && filled(config("services.{$provider}.client_secret"))
        && filled(config("services.{$provider}.redirect"))
    );
@endphp

@if ($errors->has('social_auth'))
    <x-ui.alert class="mt-6" tone="danger" role="alert">
        {{ $errors->first('social_auth') }}
    </x-ui.alert>
@endif

@if ($providers->isNotEmpty())
    <div @class([
        'mt-10 grid gap-3',
        'sm:grid-cols-1' => $providers->count() === 1,
        'sm:grid-cols-2' => $providers->count() === 2,
        'sm:grid-cols-3' => $providers->count() === 3,
    ])>
        @foreach ($providers as $provider => $label)
            <x-ui.button :href="route('social.login', $provider)" variant="secondary" class="w-full">
                <svg class="h-5 w-5 stroke-2 text-secondary" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#{{ $provider }}"></use>
                </svg>
                <span>{{ $label }}</span>
            </x-ui.button>
        @endforeach
    </div>

    <div class="my-7 flex items-center gap-3" role="presentation">
        <div class="h-px flex-1 bg-line"></div>
        <p class="text-xs font-bold uppercase tracking-wider text-muted">{{ __("or sign {$action} with email") }}</p>
        <div class="h-px flex-1 bg-line"></div>
    </div>
@endif
