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
    <div class="mt-6 rounded-sm border border-red-300 bg-red-50 p-3 text-sm text-red-700">
        {{ $errors->first('social_auth') }}
    </div>
@endif

@if ($providers->isNotEmpty())
    <div @class([
        'mt-10 grid gap-3',
        'sm:grid-cols-1' => $providers->count() === 1,
        'sm:grid-cols-2' => $providers->count() === 2,
        'sm:grid-cols-3' => $providers->count() === 3,
    ])>
        @foreach ($providers as $provider => $label)
            <a href="{{ route('social.login', $provider) }}" class="button tertiary w-full">
                <svg class="w-6 h-6 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#{{ $provider }}"></use>
                </svg>
                <span>{{ $label }}</span>
            </a>
        @endforeach
    </div>

    <div class="my-7 flex items-center space-x-3">
        <div class="h-px flex-1 bg-secondary border border-secondary"></div>
        <p class="text-xs text-primary uppercase">{{ __("or sign {$action} with email") }}</p>
        <div class="h-px flex-1 bg-secondary border border-secondary"></div>
    </div>
@endif
