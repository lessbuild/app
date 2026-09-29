@php($tones = ['idea' => 'info', 'problem' => 'danger', 'question' => 'warning', 'praise' => 'success'])
<x-signal.layouts.admin :title="__('Feedback')" :description="__('What people sent from the Send feedback form, newest first. Messages are encrypted at rest.')">
    <x-signal.ui.local-nav :label="__('Feedback')">
        <a href="{{ route('admin.feedback') }}" class="ui-local-nav__link" @unless ($resolved) aria-current="page" @endunless>{{ __('Open') }} ({{ $counts['open'] }})</a>
        <a href="{{ route('admin.feedback', ['show' => 'resolved']) }}" class="ui-local-nav__link" @if ($resolved) aria-current="page" @endif>{{ __('Resolved') }} ({{ $counts['resolved'] }})</a>
    </x-signal.ui.local-nav>

    @forelse ($items as $item)
        <x-signal.ui.card as="article" class="grid gap-3 p-5">
            <div class="flex flex-wrap items-center gap-3">
                <x-signal.ui.badge :tone="$tones[$item->kind] ?? 'neutral'">{{ __(\App\Models\Feedback::KINDS[$item->kind] ?? $item->kind) }}</x-signal.ui.badge>
                <span class="text-sm font-semibold text-ink">
                    @if ($item->user)<a href="{{ route('admin.customers.users', $item->user->id) }}" class="hover:underline">{{ $item->user->name }}</a>@else{{ __('Deleted user') }}@endif
                    @if ($item->account) · <a href="{{ route('admin.customers.accounts', $item->account->id) }}" class="hover:underline">{{ $item->account->name }}</a>@endif
                </span>
                <span class="text-xs text-muted">{{ $item->created_at?->diffForHumans() }}</span>
            </div>
            <p class="whitespace-pre-line text-sm leading-6 text-ink">{{ $item->message }}</p>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="min-w-0 truncate text-xs text-muted">
                    @if ($item->page){{ __('From :page', ['page' => $item->page]) }}@endif
                    @if ($item->resolved_at) · {{ __('Resolved by :name :when', ['name' => $item->resolver->name ?? __('someone'), 'when' => $item->resolved_at->diffForHumans()]) }}@endif
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($item->featureRequest)
                        <a href="{{ route('admin.roadmap') }}" class="text-xs font-bold text-primary underline">{{ __('On the roadmap: :title', ['title' => $item->featureRequest->title]) }}</a>
                    @else
                        <x-signal.ui.button :href="route('admin.feedback', ['dialog' => 'roadmap-'.$item->id])" variant="quiet" size="sm" :data-modal-trigger="'roadmap-'.$item->id">{{ __('Add to roadmap') }}</x-signal.ui.button>
                    @endif
                    <form method="POST" action="{{ route('admin.feedback.update', $item->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="resolved" value="{{ $item->resolved_at ? '0' : '1' }}">
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ $item->resolved_at ? __('Open again') : __('Mark resolved') }}</x-signal.ui.button>
                    </form>
                </div>
            </div>
            @unless ($item->featureRequest)
                <x-signal.overlays.form-modal :id="'roadmap-'.$item->id" :title="__('Add to roadmap')" :description="__('Link it to a request already on the roadmap, or write a new one. The sender’s vote is counted either way, and the feedback is resolved.')" :action="route('admin.feedback.roadmap', $item->id)" :submit="__('Add to roadmap')" form-class="grid items-start gap-5 sm:grid-cols-2">
                    @if ($roadmap->isNotEmpty())
                        <div class="sm:col-span-2">
                            <x-signal.ui.select-field :id="'roadmap-'.$item->id.'-existing'" name="feature_request_id" :label="__('Existing request')" :description="__('Leave on “New request” to write one below.')">
                                <option value="">{{ __('New request') }}</option>
                                @foreach ($roadmap as $request)
                                    <option value="{{ $request->id }}">{{ $request->title }} ({{ $request->statusLabel() }})</option>
                                @endforeach
                            </x-signal.ui.select-field>
                        </div>
                    @endif
                    @include('admin._feature-request-fields', ['item' => null, 'prefix' => 'roadmap-'.$item->id, 'titleRequired' => $roadmap->isEmpty()])
                </x-signal.overlays.form-modal>
            @endunless
        </x-signal.ui.card>
    @empty
        <x-signal.ui.empty-state icon="inbox" :title="$resolved ? __('Nothing resolved yet') : __('All caught up')" :description="$resolved ? __('Resolved feedback appears here.') : __('No open feedback.')" />
    @endforelse
    {{ $items->links() }}
</x-signal.layouts.admin>
