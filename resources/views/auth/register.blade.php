<x-signal.layouts.auth :title="__('Create an account')" :heading="__('Create your account')" :description="__('Start free. Turn on the services you need for each project and pay only for what you use.')">
    @unless ($open || $invite)
        <x-signal.ui.alert tone="info" class="mb-5">{{ __('Sign-up is by invitation for now. If a team invited you, use the email they invited.') }} <a href="{{ route('access-requests.create') }}" class="font-bold underline">{{ __('Request access') }}</a></x-signal.ui.alert>
    @endunless
    <form method="POST" action="{{ route('register.store') }}" class="grid gap-5">
        @csrf
        @if ($invite)<input type="hidden" name="invite" value="{{ $invite }}">@endif
        @if (is_string(request()->cookie('bp_referral')))<input type="hidden" name="referral" value="{{ request()->cookie('bp_referral') }}">@endif
        <x-signal.ui.input-field name="name" :label="__('Your name')" autocomplete="name" required autofocus />
        <x-signal.ui.input-field name="email" :label="__('Work email')" type="email" autocomplete="email" :value="old('email', $invitedEmail)" required />
        <x-signal.ui.input-field name="password" :label="__('Password')" type="password" autocomplete="new-password" required :restore="false" />
        <x-signal.ui.input-field name="password_confirmation" :label="__('Confirm password')" type="password" autocomplete="new-password" required :restore="false" />
        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Create account') }}</x-signal.ui.button>
    </form>
    <p class="text-center text-xs leading-5 text-muted">{!! __('By creating an account you agree to the :terms and :privacy.', [
        'terms' => '<a href="'.e(route('legal', 'terms')).'" class="font-semibold text-primary hover:underline">'.e(__('terms of service')).'</a>',
        'privacy' => '<a href="'.e(route('legal', 'privacy')).'" class="font-semibold text-primary hover:underline">'.e(__('privacy policy')).'</a>',
    ]) !!}</p>
    <div class="mt-5">@include('auth.partials.social-sign-in')</div>

    <x-slot:footer>
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-bold text-primary underline">{{ __('Sign in') }}</a>
    </x-slot:footer>
</x-signal.layouts.auth>
