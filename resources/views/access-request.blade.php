<x-layouts.core :title="__('Request access')" :description="__('Tell us what you plan to deploy with BuildPusher.')" :canonical="route('access-request.create')" :indexable="true" :livewire="false">
    <main id="main-content" class="min-h-screen bg-secondary px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-5xl">
            <nav class="flex items-center justify-between"><a href="/" class="text-xl font-black uppercase tracking-tight text-primary">{{ config('app.name') }}</a><a href="{{ route('login') }}" class="button primary">{{ __('Sign in') }}</a></nav>
            <div class="mt-10 grid gap-8 lg:grid-cols-[.8fr_1.2fr] lg:items-start">
                <section class="py-4"><p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Private access') }}</p><h1 class="mt-3 text-4xl font-black tracking-tight text-primary sm:text-5xl">{{ __('Bring us your deployment workflow.') }}</h1><p class="mt-5 text-lg leading-8 text-secondary">{{ __('BuildPusher is onboarding teams deliberately while we validate real production provisioning, recovery, and support. Tell us what you run and we will follow up personally.') }}</p><ul class="mt-7 space-y-3 text-sm text-secondary"><li>✓ {{ __('No payment or cloud credentials required') }}</li><li>✓ {{ __('Your request is private and encrypted at rest') }}</li><li>✓ {{ __('Existing customers can continue to sign in') }}</li></ul></section>
                <section class="rounded-2xl border border-primary bg-primary p-6 shadow-xs sm:p-8">
                    @if(session('access_requested'))
                        <div role="status" class="rounded-xl border border-green-200 bg-green-50 p-5 text-green-900"><h2 class="font-black">{{ __('Request received') }}</h2><p class="mt-1 text-sm">{{ session('access_requested') }}</p></div>
                    @else
                        <h2 class="text-2xl font-black text-primary">{{ __('Request access') }}</h2><p class="mt-1 text-sm text-secondary">{{ __('All fields marked required help us assess fit and prepare useful onboarding.') }}</p>
                        @if($errors->any())<div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p class="font-bold">{{ __('Please check the highlighted details.') }}</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                        <form method="POST" action="{{ route('access-request.store') }}" class="mt-6 grid gap-5 sm:grid-cols-2">@csrf
                            <label><span class="mb-1 block text-sm font-semibold text-primary">{{ __('Name') }} *</span><input name="name" value="{{ old('name') }}" required autocomplete="name" class="input secondary rounded-sm"></label>
                            <label><span class="mb-1 block text-sm font-semibold text-primary">{{ __('Work email') }} *</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="input secondary rounded-sm"></label>
                            <label><span class="mb-1 block text-sm font-semibold text-primary">{{ __('Company or project') }}</span><input name="company" value="{{ old('company') }}" autocomplete="organization" class="input secondary rounded-sm"></label>
                            <label><span class="mb-1 block text-sm font-semibold text-primary">{{ __('Team size') }}</span><select name="team_size" class="input secondary rounded-sm"><option value="">{{ __('Select') }}</option>@foreach(\App\Models\AccessRequest::TEAM_SIZES as $size)<option value="{{ $size }}" @selected(old('team_size')===$size)>{{ $size }}</option>@endforeach</select></label>
                            <label class="sm:col-span-2"><span class="mb-1 block text-sm font-semibold text-primary">{{ __('Plan of interest') }}</span><select name="plan" class="input secondary rounded-sm"><option value="">{{ __('Not sure yet') }}</option>@foreach(config('billing.plans') as $key=>$plan)<option value="{{ $key }}" @selected(old('plan',$selectedPlan)===$key)>{{ $plan['name'] }}</option>@endforeach</select></label>
                            <label class="sm:col-span-2"><span class="mb-1 block text-sm font-semibold text-primary">{{ __('What do you want to deploy?') }} *</span><textarea name="use_case" required minlength="20" maxlength="2000" rows="6" class="input secondary rounded-sm" placeholder="{{ __('Current stack, provider, number of servers, and the problem you want BuildPusher to solve.') }}">{{ old('use_case') }}</textarea><span class="mt-1 block text-xs text-secondary">{{ __('Please do not include passwords, tokens, or other secrets.') }}</span></label>
                            <div class="hidden" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
                            <button type="submit" class="rounded-lg bg-ternary px-6 py-3 font-bold text-white sm:col-span-2">{{ __('Send access request') }}</button>
                        </form>
                    @endif
                    <p class="mt-5 text-xs leading-5 text-secondary">{{ __('By submitting, you agree that we may contact you about BuildPusher. See our') }} <a class="underline" href="{{ route('privacy') }}">{{ __('privacy policy') }}</a>.</p>
                </section>
            </div>
        </div>
    </main>
</x-layouts.core>
