@php($tones = ['in_progress' => 'info', 'planned' => 'accent', 'under_review' => 'neutral', 'shipped' => 'success', 'declined' => 'neutral'])
<x-signal.layouts.admin :title="__('Roadmap')" :description="__('Requests on the public roadmap, written up from feedback. Moving one to Shipped tells everyone who voted for it.')">
    <x-slot:actions>
        <x-signal.ui.button :href="route('roadmap')" variant="secondary" target="_blank" rel="noopener">{{ __('View roadmap') }}</x-signal.ui.button>
        <x-signal.ui.button :href="route('admin.roadmap', ['dialog' => 'new-feature-request'])" variant="primary" data-modal-trigger="new-feature-request">{{ __('Add a request') }}</x-signal.ui.button>
    </x-slot:actions>

    <x-signal.overlays.form-modal id="new-feature-request" :title="__('Add a request')" :action="route('admin.roadmap.store')" :submit="__('Add to roadmap')" form-class="grid items-start gap-5 sm:grid-cols-2">
        @include('admin._feature-request-fields', ['item' => null, 'prefix' => 'new-feature'])
    </x-signal.overlays.form-modal>

    @forelse ($requests as $item)
        <x-signal.ui.card as="article" class="flex flex-wrap items-start justify-between gap-3 p-5">
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 font-extrabold text-ink">{{ $item->title }} <x-signal.ui.badge :tone="$tones[$item->status] ?? 'neutral'">{{ $item->statusLabel() }}</x-signal.ui.badge></p>
                @if ($item->description)<p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $item->description }}</p>@endif
                <p class="mt-2 text-xs text-muted">{{ trans_choice(':count vote|:count votes', $item->votes_count, ['count' => $item->votes_count]) }} · {{ trans_choice(':count piece of feedback|:count pieces of feedback', $item->feedback_count, ['count' => $item->feedback_count]) }}@if ($item->shipped_at) · {{ __('shipped :when', ['when' => $item->shipped_at->diffForHumans()]) }}@endif</p>
            </div>
            <x-signal.ui.button :href="route('admin.roadmap', ['dialog' => 'edit-feature-'.$item->id])" variant="secondary" size="sm" :data-modal-trigger="'edit-feature-'.$item->id">{{ __('Edit') }}</x-signal.ui.button>
            <x-signal.overlays.form-modal :id="'edit-feature-'.$item->id" :title="__('Edit request')" :action="route('admin.roadmap.update', $item->id)" method="PUT" :submit="__('Save')" form-class="grid items-start gap-5 sm:grid-cols-2">
                @include('admin._feature-request-fields', ['item' => $item, 'prefix' => 'edit-feature-'.$item->id])
            </x-signal.overlays.form-modal>
        </x-signal.ui.card>
    @empty
        <x-signal.ui.empty-state icon="list" :title="__('The roadmap is empty')" :description="__('Add a request, or put feedback on the roadmap from Feedback.')" />
    @endforelse
</x-signal.layouts.admin>
