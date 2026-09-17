<x-layouts.core
    :title="__('Request access')"
    :description="__('Tell us what you plan to deploy with BuildPusher.')"
    :canonical="route('access-request.create')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-3 focus:font-semibold focus:text-primary focus:shadow-xl">
        {{ __('Skip to main content') }}
    </a>

    <main id="main-content" tabindex="-1" class="min-h-screen bg-secondary px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
        <div class="mx-auto max-w-6xl">
            <nav class="flex items-center justify-between gap-4" aria-label="{{ __('Access request navigation') }}">
                <a href="{{ url('/') }}" class="inline-flex min-h-[2.5rem] items-center text-xl font-black uppercase tracking-tight text-primary">
                    {{ config('app.name') }}
                </a>
                <x-ui.button :href="route('login')" variant="secondary">{{ __('Sign in') }}</x-ui.button>
            </nav>

            <div class="mt-10 grid gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:items-start lg:gap-12">
                <section class="py-4 lg:py-8" aria-labelledby="access-request-heading">
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Private access') }}</p>
                    <h1 id="access-request-heading" class="mt-3 max-w-xl text-4xl font-black tracking-tight text-primary sm:text-5xl">
                        {{ __('Bring us your deployment workflow.') }}
                    </h1>
                    <p class="mt-5 max-w-xl text-lg leading-8 text-secondary">
                        {{ __('BuildPusher is onboarding teams deliberately while we validate real production provisioning, recovery, and support. Tell us what you run and we will follow up personally.') }}
                    </p>
                    <ul class="mt-8 grid gap-3 text-sm text-secondary">
                        <li class="flex items-start gap-3"><span class="mt-0.5 text-ternary" aria-hidden="true">✓</span><span>{{ __('No payment or cloud credentials required') }}</span></li>
                        <li class="flex items-start gap-3"><span class="mt-0.5 text-ternary" aria-hidden="true">✓</span><span>{{ __('Your request is private and encrypted at rest') }}</span></li>
                        <li class="flex items-start gap-3"><span class="mt-0.5 text-ternary" aria-hidden="true">✓</span><span>{{ __('Existing customers can continue to sign in') }}</span></li>
                    </ul>
                </section>

                <x-ui.card class="p-6 shadow-sm sm:p-8">
                    @if (session('access_requested'))
                        <x-ui.alert tone="success" role="status">
                            <h2 class="font-black">{{ __('Request received') }}</h2>
                            <p class="mt-1 text-sm">{{ session('access_requested') }}</p>
                        </x-ui.alert>
                    @else
                        <header>
                            <h2 class="text-2xl font-black tracking-tight text-primary">{{ __('Request access') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-secondary">{{ __('All fields marked required help us assess fit and prepare useful onboarding.') }}</p>
                        </header>

                        @if ($errors->any())
                            <x-ui.alert class="mt-5" tone="danger" role="alert">
                                <p class="font-bold">{{ __('Please check the highlighted details.') }}</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </x-ui.alert>
                        @endif

                        <form method="POST" action="{{ route('access-request.store') }}" class="mt-6 grid gap-5 sm:grid-cols-2">
                            @csrf

                            <div>
                                <label for="access-name" class="block text-sm font-semibold text-primary">{{ __('Name') }} <span aria-hidden="true">*</span></label>
                                <input id="access-name" name="name" value="{{ old('name') }}" required autocomplete="name" class="input secondary mt-2 rounded-lg">
                            </div>

                            <div>
                                <label for="access-email" class="block text-sm font-semibold text-primary">{{ __('Work email') }} <span aria-hidden="true">*</span></label>
                                <input id="access-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="input secondary mt-2 rounded-lg">
                            </div>

                            <div>
                                <label for="access-company" class="block text-sm font-semibold text-primary">{{ __('Company or project') }}</label>
                                <input id="access-company" name="company" value="{{ old('company') }}" autocomplete="organization" class="input secondary mt-2 rounded-lg">
                            </div>

                            <div>
                                <label for="access-team-size" class="block text-sm font-semibold text-primary">{{ __('Team size') }}</label>
                                <select id="access-team-size" name="team_size" class="input secondary mt-2 rounded-lg">
                                    <option value="">{{ __('Select') }}</option>
                                    @foreach (\App\Models\AccessRequest::TEAM_SIZES as $size)
                                        <option value="{{ $size }}" @selected(old('team_size') === $size)>{{ $size }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="access-plan" class="block text-sm font-semibold text-primary">{{ __('Plan of interest') }}</label>
                                <select id="access-plan" name="plan" class="input secondary mt-2 rounded-lg">
                                    <option value="">{{ __('Not sure yet') }}</option>
                                    @foreach (config('billing.plans') as $key => $plan)
                                        <option value="{{ $key }}" @selected(old('plan', $selectedPlan) === $key)>{{ $plan['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="access-use-case" class="block text-sm font-semibold text-primary">{{ __('What do you want to deploy?') }} <span aria-hidden="true">*</span></label>
                                <textarea id="access-use-case" name="use_case" required minlength="20" maxlength="2000" rows="6" class="input secondary mt-2 rounded-lg" placeholder="{{ __('Current stack, provider, number of servers, and the problem you want BuildPusher to solve.') }}">{{ old('use_case') }}</textarea>
                                <span class="mt-1 block text-xs text-secondary">{{ __('Please do not include passwords, tokens, or other secrets.') }}</span>
                            </div>

                            <div class="hidden" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>

                            <x-ui.button type="submit" variant="primary" class="w-full sm:col-span-2">
                                {{ __('Send access request') }}
                            </x-ui.button>
                        </form>
                    @endif

                    <p class="mt-5 text-xs leading-5 text-secondary">
                        {{ __('By submitting, you agree that we may contact you about BuildPusher. See our') }}
                        <a class="underline hover:text-primary" href="{{ route('privacy') }}">{{ __('privacy policy') }}</a>.
                    </p>
                </x-ui.card>
            </div>
        </div>
    </main>
</x-layouts.core>
