<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :provider', ['provider' => $provider->name])"
        :route="route('providers.show', $provider)"
    />

    <x-layouts.partials.heading
        eyebrow="{{ __('Provider operations') }}"
        icon="activity"
        :title="__('Connection check history')"
        :description="__('Review the retained credential-check evidence for :provider.', ['provider' => $provider->name])"
    />

    <div class="mt-6">
        @include('components.scenes.providers.connection-checks-content')
    </div>
</x-layouts.app>
