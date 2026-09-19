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
    <section
        class="ui-card mb-12 overflow-hidden border-ternary shadow-xs"
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
        <div class="border-b border-primary p-4 lg:hidden">
            <div class="overflow-x-auto pb-1" role="tablist" aria-label="{{ __('Workspace setup steps') }}">
                <div class="flex min-w-max gap-2">
                    @foreach ($onboardingSteps as $key => $step)
                        @php($complete = $onboarding[$key])
                        <button
                            id="setup-tab-{{ $key }}"
                            type="button"
                            role="tab"
                            class="dashboard-setup-tab flex min-h-[44px] items-center gap-2 rounded-lg border px-3 py-2 text-left text-xs font-bold focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ternary focus-visible:ring-offset-2"
                            aria-controls="setup-panel-{{ $key }}"
                            aria-selected="{{ $key === $defaultOnboardingStep ? 'true' : 'false' }}"
                            :aria-selected="(activeSetupStep === '{{ $key }}').toString()"
                            :tabindex="activeSetupStep === '{{ $key }}' ? '0' : '-1'"
                            @click="activeSetupStep = '{{ $key }}'"
                            @keydown.right.prevent="moveSetupStep(1)"
                            @keydown.left.prevent="moveSetupStep(-1)"
                            @keydown.home.prevent="focusSetupStep(0)"
                            @keydown.end.prevent="focusSetupStep(setupSteps.length - 1)"
                        >
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-[10px] font-black text-primary" aria-hidden="true">{{ $complete ? '✓' : $loop->iteration }}</span>
                            <span>{{ $step['title'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
        <ol class="grid gap-px bg-secondary md:grid-cols-2 xl:grid-cols-5">
            @foreach ($onboardingSteps as $key => $step)
                @php($complete = $onboarding[$key])
                @php($current = $currentOnboardingStep === $key)
                <li id="setup-panel-{{ $key }}" data-dashboard-setup-step x-show="activeSetupStep === '{{ $key }}'" @class([
                    'dashboard-setup-step min-h-0 flex-col bg-primary p-4 sm:min-h-52 sm:p-5',
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
                    <h3 class="mt-3 font-semibold text-primary sm:mt-4">{{ $step['title'] }}</h3>
                    <p class="mt-1 flex-1 text-sm leading-5 text-secondary sm:mt-2 sm:leading-6">{{ $step['description'] }}</p>
                    @if ($complete)
                        <a href="{{ $step['reviewUrl'] }}" class="mt-3 text-sm font-semibold text-ternary underline sm:mt-4">{{ __('Review') }}</a>
                    @elseif ($current)
                        @if ($step['modalId'])
                            <x-ui.button
                                :href="$step['createUrl']"
                                data-modal-trigger="{{ $step['modalId'] }}"
                                aria-controls="{{ $step['modalId'] }}"
                                aria-expanded="{{ ($dashboardModalOpen[$key] ?? false) ? 'true' : 'false' }}"
                                variant="primary"
                                class="mt-3 w-full sm:mt-4"
                            >{{ __('Continue setup') }}</x-ui.button>
                        @else
                            <x-ui.button :href="$step['createUrl']" variant="primary" class="mt-3 w-full sm:mt-4">{{ __('Deploy repository') }}</x-ui.button>
                        @endif
                    @else
                        <span class="mt-3 text-xs font-medium text-secondary sm:mt-4">{{ __('Available after the previous step') }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
