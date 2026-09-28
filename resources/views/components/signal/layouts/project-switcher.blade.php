@props(['shell', 'variant' => 'pill'])

@if ($shell->account !== null)
    <x-signal.layouts.switcher
        {{ $attributes }}
        :variant="$variant"
        :label="__('Project')"
        :current="$shell->project?->name ?? __('All projects')"
        icon="tasks"
        :items="array_map(fn (array $project): array => ['name' => $project['name'], 'url' => route('projects.show', $project['id']), 'current' => $project['id'] === $shell->project?->id], $shell->projects)"
        :empty-text="__('No projects yet.')"
    >
        <div class="mt-1 grid gap-1 border-t border-line pt-1">
            <a href="{{ route('dashboard') }}" class="rounded-control px-3 py-2 text-sm font-bold text-primary hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus">{{ __('View all projects') }}</a>
            @if ($shell->canCreateProject)
                <a href="{{ route('projects.create') }}" data-modal-trigger="new-project" data-modal-history-url="{{ request()->fullUrlWithQuery(['dialog' => 'new-project']) }}" class="rounded-control px-3 py-2 text-sm font-bold text-primary hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus">{{ __('New project') }}</a>
            @endif
        </div>
    </x-signal.layouts.switcher>
@endif
