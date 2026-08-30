<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Builds')"
        :route="route('builds.index')"
    />

    <x-layouts.partials.heading
        :title="__('Build #:id', ['id' => $build->id])"
        :description="$build->repository->name"
    >
        <x-slot:buttons>
            <a href="{{ route('repositories.show', $build->repository) }}" class="button primary">
                {{ __('View repository') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <livewire:build-deployment-status :build="$build" />
</x-layouts.app>
