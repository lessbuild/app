<x-signal.layouts.core :title="__('Create your workspace')" :description="__('Create a Buildpusher account and workspace.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <div class="w-full">
            <a href="{{ route('platform.login') }}" class="mb-6 inline-flex items-center gap-3 rounded-control text-lg font-extrabold tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus">
                <span class="grid h-10 w-10 place-items-center rounded-card bg-ink text-surface" aria-hidden="true">↗</span>
                <span>{{ config('app.name') }}</span>
            </a>

            <x-signal.ui.card class="p-6 sm:p-8">
                <p class="ui-eyebrow">{{ __('Get started') }}</p>
                <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Create your workspace') }}</h1>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('One account for Buildpusher. Product access and subscriptions are managed separately for each app.') }}</p>

                <form method="POST" action="{{ route('platform.register.store') }}" class="mt-6 grid gap-5">
                    @csrf
                    <x-signal.ui.field :label="__('Your name')" name="name" required>
                        <x-signal.ui.input name="name" autocomplete="name" required autofocus />
                    </x-signal.ui.field>

                    <x-signal.ui.field :label="__('Email address')" name="email" required>
                        <x-signal.ui.input name="email" type="email" autocomplete="email" required />
                    </x-signal.ui.field>

                    <x-signal.ui.field :label="__('Password')" name="password" required>
                        <x-signal.ui.input name="password" type="password" autocomplete="new-password" minlength="12" required />
                        <x-slot:description>{{ __('Use at least 12 characters.') }}</x-slot:description>
                    </x-signal.ui.field>

                    <x-signal.ui.field :label="__('Confirm password')" name="password_confirmation" required>
                        <x-signal.ui.input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required />
                    </x-signal.ui.field>

                    <x-signal.ui.field :label="__('Workspace name')" name="workspace_name" required>
                        <x-signal.ui.input name="workspace_name" autocomplete="organization" required />
                        <x-slot:description>{{ __('Your team can join this workspace later. No product plan is started during sign-up.') }}</x-slot:description>
                    </x-signal.ui.field>

                    <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">
                        {{ __('Create account and workspace') }}
                    </x-signal.ui.button>
                </form>
            </x-signal.ui.card>

            <p class="mt-5 text-center text-sm text-muted">
                {{ __('Already have an account?') }}
                <a href="{{ route('platform.login') }}" class="font-bold text-primary underline">{{ __('Sign in') }}</a>
            </p>
        </div>
    </main>
</x-signal.layouts.core>
