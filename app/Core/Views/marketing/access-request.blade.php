<x-signal.layouts.core
    :title="__('Request Deployer access')"
    :description="__('Request access to Buildpusher Deployer.')"
    :canonical="route('core.access-request.create')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-screen-xl gap-8 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[.8fr_1.2fr] lg:items-start">
        <section class="py-4 lg:py-8" aria-labelledby="access-request-heading">
            <x-signal.ui.page-header
                title-id="access-request-heading"
                :eyebrow="__('Private access')"
                :title="__('Bring us your deployment workflow.')"
                :description="__('Deployer is onboarding teams deliberately while we validate real production provisioning, recovery, and support. Tell us what you run and we will follow up personally.')"
            >
                <x-slot:metadata>
                    <x-signal.ui.badge tone="success">{{ __('No payment or cloud credentials required') }}</x-signal.ui.badge>
                    <x-signal.ui.badge tone="neutral">{{ __('Private and encrypted at rest') }}</x-signal.ui.badge>
                </x-slot:metadata>
            </x-signal.ui.page-header>
            <x-signal.ui.button :href="route('core.pricing')" variant="secondary">{{ __('Compare Deployer plans') }}</x-signal.ui.button>
        </section>

        <x-signal.ui.card class="p-6 sm:p-8">
            @if (session('access_requested'))
                <x-signal.ui.alert tone="success" role="status">
                    <h2 class="font-extrabold">{{ __('Request received') }}</h2>
                    <p class="mt-1 text-sm">{{ session('access_requested') }}</p>
                </x-signal.ui.alert>
            @else
                <header>
                    <h2 class="text-2xl font-extrabold tracking-tight text-ink">{{ __('Request Deployer access') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-muted">{{ __('All fields marked required help us assess fit and prepare useful onboarding.') }}</p>
                </header>

                @if ($errors->any())
                    <x-signal.ui.alert class="mt-5" tone="danger" role="alert">
                        <p class="font-bold">{{ __('Please check the highlighted details.') }}</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-signal.ui.alert>
                @endif

                <form method="POST" action="{{ route('core.access-request.store') }}" class="mt-6 grid gap-5 sm:grid-cols-2">
                    @csrf

                    <div>
                        <label for="access-name" class="ui-label">{{ __('Name') }} <span aria-hidden="true">*</span></label>
                        <x-signal.ui.input id="access-name" name="name" :value="old('name')" required autocomplete="name" class="ui-input" :restore="false" />
                    </div>

                    <div>
                        <label for="access-email" class="ui-label">{{ __('Work email') }} <span aria-hidden="true">*</span></label>
                        <x-signal.ui.input id="access-email" type="email" name="email" :value="old('email')" required autocomplete="email" class="ui-input" :restore="false" />
                    </div>

                    <div>
                        <label for="access-company" class="ui-label">{{ __('Company or project') }}</label>
                        <x-signal.ui.input id="access-company" name="company" :value="old('company')" autocomplete="organization" class="ui-input" :restore="false" />
                    </div>

                    <div>
                        <label for="access-team-size" class="ui-label">{{ __('Team size') }}</label>
                        <x-signal.ui.select id="access-team-size" name="team_size" class="ui-input">
                            <option value="">{{ __('Select') }}</option>
                            @foreach ($teamSizes as $size)
                                <option value="{{ $size }}" @selected(old('team_size') === $size)>{{ $size }}</option>
                            @endforeach
                        </x-signal.ui.select>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="access-plan" class="ui-label">{{ __('Deployer plan of interest') }}</label>
                        <x-signal.ui.select id="access-plan" name="plan" class="ui-input">
                            <option value="">{{ __('Not sure yet') }}</option>
                            @foreach ($plans as $key => $plan)
                                <option value="{{ $key }}" @selected(old('plan', $selectedPlan) === $key)>{{ $plan['name'] }}</option>
                            @endforeach
                        </x-signal.ui.select>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="access-use-case" class="ui-label">{{ __('What do you want to deploy?') }} <span aria-hidden="true">*</span></label>
                        <x-signal.ui.textarea id="access-use-case" name="use_case" required minlength="20" maxlength="2000" rows="6" class="ui-input" :placeholder="__('Current stack, provider, number of servers, and the problem you want Deployer to solve.')" :restore="false">{{ old('use_case') }}</x-signal.ui.textarea>
                        <span class="ui-help">{{ __('Please do not include passwords, tokens, or other secrets.') }}</span>
                    </div>

                    <div class="hidden" aria-hidden="true">
                        <label>Website<x-signal.ui.input name="website" tabindex="-1" autocomplete="off" :restore="false" /></label>
                    </div>

                    <x-signal.ui.button type="submit" variant="primary" class="w-full sm:col-span-2">{{ __('Send access request') }}</x-signal.ui.button>
                </form>
            @endif

            <p class="mt-5 text-xs leading-5 text-muted">
                {{ __('By submitting, you agree that we may contact you about Deployer. See our') }}
                <a class="ui-link" href="{{ route('core.privacy') }}">{{ __('privacy policy') }}</a>.
            </p>
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
