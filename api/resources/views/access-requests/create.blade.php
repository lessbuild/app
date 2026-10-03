<x-signal.layouts.auth :title="__('Request access')" :heading="__('Request access')" :description="__('We’re letting people in a few at a time. Tell us a little about what you’d build and we’ll email you an invitation.')">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status" class="mb-5">{{ session('status') }}</x-signal.ui.alert>
    @endif
    <form method="POST" action="{{ route('access-requests.store') }}" class="grid gap-5">
        @csrf
        <x-signal.ui.input-field name="name" :label="__('Your name')" autocomplete="name" maxlength="120" required autofocus />
        <x-signal.ui.input-field name="email" :label="__('Work email')" type="email" autocomplete="email" maxlength="255" required />
        <x-signal.ui.input-field name="company" :label="__('Company (optional)')" autocomplete="organization" maxlength="120" />
        <x-signal.ui.select-field name="team_size" :label="__('Team size')">
            <option value="">{{ __('Rather not say') }}</option>
            @foreach ($teamSizes as $size)
                <option value="{{ $size }}">{{ $size }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.textarea-field name="use_case" :label="__('What would you use it for?')" rows="4" maxlength="2000" required />
        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Request access') }}</x-signal.ui.button>
    </form>

    <x-slot:footer>
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-bold text-primary underline">{{ __('Sign in') }}</a>
    </x-slot:footer>
</x-signal.layouts.auth>
