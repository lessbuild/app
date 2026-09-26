@php
    $onboardingSteps = [
        'provider' => ['title' => __('Connect a provider'), 'description' => __('Add cloud credentials for server provisioning.'), 'createUrl' => $dashboardProviderCreateUrl, 'reviewUrl' => route('providers.index'), 'modalId' => 'provider-create-dialog'],
        'server' => ['title' => __('Provision a server'), 'description' => __('Create the application server that will run your sites.'), 'createUrl' => $dashboardServerCreateUrl, 'reviewUrl' => route('servers.index'), 'modalId' => 'server-create-dialog'],
        'website' => ['title' => __('Add a website'), 'description' => __('Choose a domain and place it on an active server.'), 'createUrl' => $dashboardWebsiteCreateUrl, 'reviewUrl' => route('websites.index'), 'modalId' => 'website-create-dialog'],
        'repository' => ['title' => __('Connect a repository'), 'description' => __('Attach the Git source and deployment settings.'), 'createUrl' => $dashboardRepositoryCreateUrl, 'reviewUrl' => route('repositories.index'), 'modalId' => 'repository-create-dialog'],
        'deployment' => ['title' => __('Complete a deployment'), 'description' => __('Ship a revision and verify the release succeeds.'), 'createUrl' => route('repositories.index'), 'reviewUrl' => route('builds.index'), 'modalId' => null],
    ];
    $onboardingCompleted = collect($onboarding)->filter()->count();
    $currentOnboardingStep = collect($onboarding)->search(fn (bool $complete): bool => ! $complete);
    $onboardingStepKeys = array_keys($onboardingSteps);
    $defaultOnboardingStep = $currentOnboardingStep !== false ? $currentOnboardingStep : $onboardingStepKeys[0];
@endphp

@if (in_array('setup', $dashboardWidgets, true) && $onboardingCompleted < count($onboardingSteps))
    <x-signal.ui.panel as="section"
        class="ui-panel mb-12 overflow-hidden border-line"
        aria-labelledby="setup-progress-title"
        x-data="{
            activeSetupStep: @js($defaultOnboardingStep),
            setupSteps: @js($onboardingStepKeys),
            focusSetupStep(index) {
                this.activeSetupStep = this.setupSteps[(index + this.setupSteps.length) % this.setupSteps.length];
                this.$nextTick(() => document.getElementById('setup-tab-' + this.activeSetupStep)?.focus());
            },
            moveSetupStep(offset) {
                this.focusSetupStep(this.setupSteps.indexOf(this.activeSetupStep) + offset);
            },
        }"
    >
        <div class="border-b border-line bg-surface-muted p-5 sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Workspace setup') }}</p>
                    <h2 id="setup-progress-title" class="mt-1 text-2xl font-extrabold tracking-tight text-ink">{{ __('Get to your first healthy deployment') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-muted">{{ __('Follow the dependency order once, then manage every resource from the same workspace.') }}</p>
                </div>
                <p class="text-sm font-bold text-ink">{{ __(':complete of :total complete', ['complete' => $onboardingCompleted, 'total' => count($onboardingSteps)]) }}</p>
            </div>
            <x-signal.ui.progress class="mt-4" label="{{ __('Workspace setup progress') }}" :value="$onboardingCompleted" :max="count($onboardingSteps)" />
        </div>
        <div class="border-b border-line p-4 lg:hidden">
            <div class="overflow-x-auto pb-1" role="tablist" aria-label="{{ __('Workspace setup steps') }}">
                <div class="flex min-w-max gap-2">
                    @foreach ($onboardingSteps as $key => $step)
                        @php($complete = $onboarding[$key])
                        <x-signal.ui.button variant="secondary"
                            id="setup-tab-{{ $key }}"
                            type="button"
                            role="tab"
                            class="ui-btn ui-btn-secondary ui-btn-sm dashboard-setup-tab min-h-[44px] gap-2 text-left text-xs"
                            aria-controls="setup-panel-{{ $key }}"
                            aria-selected="{{ $key === $defaultOnboardingStep ? 'true' : 'false' }}"
                            x-bind:aria-selected="(activeSetupStep === '{{ $key }}').toString()"
                            x-bind:tabindex="activeSetupStep === '{{ $key }}' ? '0' : '-1'"
                            @click="activeSetupStep = '{{ $key }}'"
                            @keydown.right.prevent="moveSetupStep(1)"
                            @keydown.left.prevent="moveSetupStep(-1)"
                            @keydown.home.prevent="focusSetupStep(0)"
                            @keydown.end.prevent="focusSetupStep(setupSteps.length - 1)"
                        >
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[10px] font-extrabold text-ink" aria-hidden="true">{{ $complete ? '✓' : $loop->iteration }}</span>
                            <span>{{ $step['title'] }}</span>
                        </x-signal.ui.button>
                    @endforeach
                </div>
            </div>
        </div>
        <ol class="grid gap-px bg-surface-muted md:grid-cols-2 xl:grid-cols-5">
            @foreach ($onboardingSteps as $key => $step)
                @php($complete = $onboarding[$key])
                @php($current = $currentOnboardingStep === $key)
                <li id="setup-panel-{{ $key }}" data-dashboard-setup-step x-show="activeSetupStep === '{{ $key }}'" @class([
                    'dashboard-setup-step min-h-0 flex-col bg-surface p-4 sm:min-h-52 sm:p-5',
                    'ring-2 ring-inset ring-focus' => $current,
                ])>
                    <div class="flex items-center justify-between gap-3">
                        <x-signal.ui.badge :tone="$complete ? 'success' : ($current ? 'accent' : 'neutral')" class="flex h-8 w-8 items-center justify-center rounded-full p-0 text-sm font-bold">{{ $complete ? '✓' : $loop->iteration }}</x-signal.ui.badge>
                        <span @class([
                            'text-xs font-bold uppercase tracking-wide',
                            'text-success' => $complete,
                            'text-ink' => $current,
                            'text-muted' => ! $complete && ! $current,
                        ])>{{ $complete ? __('Complete') : ($current ? __('Current step') : __('Upcoming')) }}</span>
                    </div>
                    <h3 class="mt-3 font-extrabold text-ink sm:mt-4">{{ $step['title'] }}</h3>
                    <p class="mt-1 flex-1 text-sm leading-5 text-muted sm:mt-2 sm:leading-6">{{ $step['description'] }}</p>
                    @if ($complete)
                        <a href="{{ $step['reviewUrl'] }}" class="ui-link mt-3 inline-flex text-sm sm:mt-4">{{ __('Review') }}</a>
                    @elseif ($current)
                        @if ($step['modalId'])
                            <x-signal.ui.button
                                :href="$step['createUrl']"
                                data-modal-trigger="{{ $step['modalId'] }}"
                                aria-controls="{{ $step['modalId'] }}"
                                aria-expanded="{{ ($dashboardModalOpen[$key] ?? false) ? 'true' : 'false' }}"
                                variant="primary"
                                class="mt-3 w-full sm:mt-4"
                            >{{ __('Continue setup') }}</x-signal.ui.button>
                        @else
                            <x-signal.ui.button :href="$step['createUrl']" variant="primary" class="mt-3 w-full sm:mt-4">{{ __('Deploy repository') }}</x-signal.ui.button>
                        @endif
                    @else
                        <span class="mt-3 text-xs font-medium text-muted sm:mt-4">{{ __('Available after the previous step') }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </x-signal.ui.panel>
@endif
