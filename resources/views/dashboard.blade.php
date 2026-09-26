<x-signal.layouts.base :title="__('Dashboard')">
    <main id="main-content" class="mx-auto w-full max-w-4xl space-y-6 px-4 py-10 sm:px-6">
        @if (session('status'))
            <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
        @endif
        <x-signal.ui.page-header :eyebrow="$account?->name" :title="__('Welcome, :name', ['name' => auth()->user()?->name])" :description="__('Projects and services arrive here in the next phase.')" />
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-signal.ui.button variant="secondary" type="submit">{{ __('Sign out') }}</x-signal.ui.button>
        </form>
    </main>
</x-signal.layouts.base>
