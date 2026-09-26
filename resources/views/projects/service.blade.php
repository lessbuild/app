<x-signal.layouts.project :overview="$overview" :title="$service->name()" :description="$service->tagline()">
    @if ($enabled)
        <x-signal.ui.empty-state :icon="$service->icon()" :title="__(':service is on for :project', ['service' => $service->name(), 'project' => $overview->project->name])" :description="__(':service’s pages are being rebuilt for the new platform and will appear here.', ['service' => $service->name()])">
            @if ($canManage)
                <x-slot:action>
                    <x-signal.ui.button variant="quiet" data-modal-trigger="disable-service">{{ __('Turn off :service', ['service' => $service->name()]) }}</x-signal.ui.button>
                </x-slot:action>
            @endif
        </x-signal.ui.empty-state>
        @if ($canManage)
            <x-signal.overlays.delete-confirmation
                id="disable-service"
                :route="route('projects.services.destroy', [$overview->project, $service->key()])"
                :title="__('Turn off :service?', ['service' => $service->name()])"
                :description="__('It stops for :project. Its data is kept, so turning it back on picks up where it left off.', ['project' => $overview->project->name])"
                :warning="__('Nothing is deleted.')"
                :submit-label="__('Turn off')"
            />
        @endif
    @else
        <x-signal.ui.empty-state :icon="$service->icon()" :title="__(':service isn’t on for this project', ['service' => $service->name()])" :description="$service->tagline()">
            <x-slot:action>
                @if ($canManage)
                    <form method="POST" action="{{ route('projects.services.store', [$overview->project, $service->key()]) }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Turn on :service', ['service' => $service->name()]) }}</x-signal.ui.button>
                    </form>
                @else
                    <p class="text-sm text-muted">{{ __('Someone who manages projects can turn it on.') }}</p>
                @endif
            </x-slot:action>
        </x-signal.ui.empty-state>
    @endif
</x-signal.layouts.project>
