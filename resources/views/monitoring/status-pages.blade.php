@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Status pages')" :description="__('Public pages that show customers how your services are doing. They belong to :account and can show monitors from any of its projects.', ['account' => $project->account->name])">
    @if ($canManage)
        <div class="flex justify-end">
            <x-signal.ui.button :href="route('monitoring.status-pages.create', $project)" variant="primary">{{ __('Add a status page') }}</x-signal.ui.button>
        </div>
    @endif

    @if ($pages->isEmpty())
        <x-signal.ui.empty-state icon="globe" :title="__('No status pages yet')" :description="__('Pick the monitors customers should see, then share one address for service health, incidents and planned maintenance.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Status pages') }}">
                @foreach ($pages as $page)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('monitoring.status-pages.show', [$project, $page->id]) }}" class="font-extrabold text-ink hover:underline">{{ $page->name }}</a>
                            <p class="mt-0.5 break-all text-xs text-muted">/status/{{ $page->slug }} · {{ trans_choice(':count component|:count components', $page->components_count ?? 0, ['count' => $page->components_count ?? 0]) }} · {{ trans_choice(':count subscriber|:count subscribers', $page->subscriptions_count ?? 0, ['count' => $page->subscriptions_count ?? 0]) }}</p>
                        </div>
                        <x-signal.ui.badge :tone="$page->published ? 'success' : 'neutral'">{{ $page->published ? __('Published') : __('Draft') }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.project>
