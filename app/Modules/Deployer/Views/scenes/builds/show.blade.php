<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Builds')"
        :route="route('builds.index')"
    />

    <x-signal.ui.page-header
        :title="__('Build #:id', ['id' => $build->id])"
        :description="$build->repository->name"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('repositories.show', $build->repository)" variant="primary">
                {{ __('View repository') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <livewire:build-deployment-status :build="$build" />
</x-layouts.app>
