<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Build #:id', ['id' => $build->id])"
        :route="route('builds.show', $build)"
    />

    <x-layouts.partials.heading
        :title="__('Compare deployments')"
        :description="$build->repository->name"
    >
        <x-slot:buttons>
            <x-signal.ui.button :href="route('builds.compare', ['build' => $baseline, 'baseline' => $build])" variant="secondary">
                {{ __('Swap comparison') }}
            </x-signal.ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <div class="mt-6">
        @include('components.scenes.builds.comparison-content', ['fragment' => false])
    </div>
</x-layouts.app>
