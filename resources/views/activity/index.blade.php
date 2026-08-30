<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Activity')"
        :description="__('A chronological history of your infrastructure, deployments, and server commands.')"
    />

    <x-activity-feed :events="$events" />

    <div class="mt-6">
        {{ $events->links() }}
    </div>
</x-layouts.app>
