<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Repositories')"
        :route="route('repositories.index')"
    ></x-layouts.partials.breadcrumbs>

    <x-layouts.partials.heading
        icon="code"
        :title="__('Deployment impact preview')"
        :description="__('See which enabled repository targets are affected by a changed-file set before any automatic push deployment.')"
    ></x-layouts.partials.heading>

    <div class="mt-8">
        @include('components.scenes.repositories.impact-preview-content', ['fragment' => false])
    </div>
</x-layouts.app>
