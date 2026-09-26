<x-signal.layouts.app :title="__('Dashboard')">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    <x-signal.ui.page-header :eyebrow="$account?->name" :title="__('Welcome, :name', ['name' => auth()->user()?->name])" :description="__('Projects and services arrive here in the next phase.')" />
</x-signal.layouts.app>
