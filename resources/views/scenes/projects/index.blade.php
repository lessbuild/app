<x-layouts.app>
    <div class="flex flex-wrap items-end justify-between gap-4"><x-layouts.partials.heading icon="view-grid" :title="__('Applications')" :description="__('Organize infrastructure into isolated production, staging, development, and preview environments.')" /><a href="{{ route('projects.create') }}" class="button primary">{{ __('New application') }}</a></div>
    <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse($projects as $project)<a href="{{ route('projects.show', $project) }}" class="rounded-2xl border border-primary bg-primary p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg"><div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-black text-primary">{{ $project->name }}</h2><p class="mt-1 text-sm text-secondary">{{ $project->description ?: __('No description') }}</p></div><span class="rounded-full bg-secondary px-3 py-1 text-xs font-bold text-secondary">{{ trans_choice(':count environment|:count environments', $project->environments_count, ['count' => $project->environments_count]) }}</span></div><p class="mt-6 font-mono text-xs text-secondary">{{ $project->slug }}</p></a>
        @empty<x-lists.empty :title="__('No applications yet')" :description="__('Create an application to group environments and deployment settings.')" />@endforelse
    </div>
</x-layouts.app>
