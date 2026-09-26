@php($using = array_values(array_filter($projects, fn ($row) => $row->enabled)))

<x-signal.layouts.app :title="$service->name()" :description="$service->tagline()">
    <x-signal.ui.page-header :eyebrow="$account->name" :title="$service->name()" :description="$service->tagline()" />

    @if ($projects === [])
        <x-signal.ui.empty-state :icon="$service->icon()" :title="__('No projects yet')" :description="__('Create a project, then turn on :service for it.', ['service' => $service->name()])" />
    @else
        <x-signal.ui.settings-section :title="__('Projects')" :description="trans_choice(':service is on for :count project.|:service is on for :count projects.', count($using), ['service' => $service->name(), 'count' => count($using)])">
            <ul class="divide-y divide-line" aria-label="{{ __('Projects') }}">
                @foreach ($projects as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                        <span class="flex min-w-0 flex-wrap items-center gap-2">
                            <a href="{{ route('projects.show', $row->projectId) }}" class="truncate font-bold text-ink hover:underline">{{ $row->projectName }}</a>
                            @if ($row->enabled)
                                <x-signal.ui.badge tone="success">{{ __('On') }}</x-signal.ui.badge>
                            @endif
                        </span>
                        @if ($row->enabled)
                            <x-signal.ui.button :href="route('projects.services.show', [$row->projectId, $service->key()])" variant="secondary" size="sm">{{ __('Open') }}</x-signal.ui.button>
                        @elseif ($row->canManage)
                            <form method="POST" action="{{ route('projects.services.store', [$row->projectId, $service->key()]) }}">
                                @csrf
                                <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Turn on') }}</x-signal.ui.button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.app>
