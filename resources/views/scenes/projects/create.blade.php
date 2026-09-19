<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('projects.index')" :title="__('Back to applications')" />
    <form method="POST" action="{{ route('projects.store') }}" class="mx-auto mt-8 max-w-4xl">
        @csrf
        <x-ui.card class="overflow-hidden">
            <div class="border-b border-primary px-5 py-5 sm:px-8">
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Applications') }}</p>
                <h1 class="mt-1 text-xl font-black text-primary">{{ __('New application') }}</h1>
                <p class="mt-1 text-sm text-secondary">{{ __('Start from a production-ready template, then customize every runtime setting.') }}</p>
            </div>
            <x-scenes.projects._create-form :templates="$templates" />
            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-8">
                <x-ui.button :href="route('projects.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary">{{ __('Create application') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</x-layouts.app>
