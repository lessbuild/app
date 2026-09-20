<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :website', ['website' => $website->name])"
        :route="route('websites.show', $website)"
    />

    <x-layouts.partials.heading
        :title="__('Health check history')"
        :description="__('Review the retained health evidence for :website.', ['website' => $website->name])"
    />

    <div class="mt-8">
        @include('components.scenes.websites.health-checks-content')
    </div>
</x-layouts.app>
