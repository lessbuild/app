@php
    $onboardingSteps = [
        'provider' => ['title' => __('Connect a provider'), 'description' => __('Add cloud credentials for server provisioning.'), 'createUrl' => route('providers.create'), 'reviewUrl' => route('providers.index')],
        'server' => ['title' => __('Provision a server'), 'description' => __('Create the application server that will run your sites.'), 'createUrl' => route('servers.create'), 'reviewUrl' => route('servers.index')],
        'website' => ['title' => __('Add a website'), 'description' => __('Choose a domain and place it on an active server.'), 'createUrl' => route('websites.create'), 'reviewUrl' => route('websites.index')],
        'repository' => ['title' => __('Connect a repository'), 'description' => __('Attach the Git source and deployment settings.'), 'createUrl' => route('repositories.create'), 'reviewUrl' => route('repositories.index')],
        'deployment' => ['title' => __('Complete a deployment'), 'description' => __('Ship a revision and verify the release succeeds.'), 'createUrl' => route('repositories.index'), 'reviewUrl' => route('builds.index')],
    ];
    $onboardingCompleted = collect($onboarding)->filter()->count();
    $currentOnboardingStep = collect($onboarding)->search(fn (bool $complete): bool => ! $complete);
@endphp

@if (in_array('setup', $dashboardWidgets, true) && $onboardingCompleted < count($onboardingSteps))
    <section class="ui-card mb-12 overflow-hidden border-ternary shadow-xs" aria-labelledby="setup-progress-title">
        <div class="border-b border-primary bg-secondary p-5 sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Workspace setup') }}</p>
                    <h2 id="setup-progress-title" class="mt-1 text-2xl font-semibold text-primary">{{ __('Get to your first healthy deployment') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-secondary">{{ __('Follow the dependency order once, then manage every resource from the same workspace.') }}</p>
                </div>
                <p class="text-sm font-bold text-primary">{{ __(':complete of :total complete', ['complete' => $onboardingCompleted, 'total' => count($onboardingSteps)]) }}</p>
            </div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-primary" role="progressbar" aria-label="{{ __('Workspace setup progress') }}" aria-valuemin="0" aria-valuemax="{{ count($onboardingSteps) }}" aria-valuenow="{{ $onboardingCompleted }}">
                <div class="h-full rounded-full bg-ternary transition-all" style="width: {{ ($onboardingCompleted / count($onboardingSteps)) * 100 }}%"></div>
            </div>
        </div>
        <ol class="grid gap-px bg-secondary md:grid-cols-2 xl:grid-cols-5">
            @foreach ($onboardingSteps as $key => $step)
                @php($complete = $onboarding[$key])
                @php($current = $currentOnboardingStep === $key)
                <li @class([
                    'flex min-h-52 flex-col bg-primary p-5',
                    'ring-2 ring-inset ring-blue-500' => $current,
                ])>
                    <div class="flex items-center justify-between gap-3">
                        <span @class([
                            'flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold',
                            'bg-green-100 text-green-800' => $complete,
                            'bg-secondary text-primary' => $current,
                            'bg-secondary text-secondary' => ! $complete && ! $current,
                        ])>{{ $complete ? '✓' : $loop->iteration }}</span>
                        <span @class([
                            'text-xs font-bold uppercase tracking-wide',
                            'text-green-700' => $complete,
                            'text-ternary' => $current,
                            'text-secondary' => ! $complete && ! $current,
                        ])>{{ $complete ? __('Complete') : ($current ? __('Current step') : __('Upcoming')) }}</span>
                    </div>
                    <h3 class="mt-4 font-semibold text-primary">{{ $step['title'] }}</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-secondary">{{ $step['description'] }}</p>
                    @if ($complete)
                        <a href="{{ $step['reviewUrl'] }}" class="mt-4 text-sm font-semibold text-ternary underline">{{ __('Review') }}</a>
                    @elseif ($current)
                        <x-ui.button :href="$step['createUrl']" variant="primary" class="mt-4 w-full">{{ $key === 'deployment' ? __('Deploy repository') : __('Continue setup') }}</x-ui.button>
                    @else
                        <span class="mt-4 text-xs font-medium text-secondary">{{ __('Available after the previous step') }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
