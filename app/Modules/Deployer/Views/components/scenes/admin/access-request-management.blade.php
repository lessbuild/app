@props(['requests', 'status', 'counts', 'editingRequest', 'reviewDialogId', 'reviewDialogOpen', 'routeNames'])

    <x-signal.ui.page-header
        icon="user-add"
        :title="__('Access requests')"
        :description="__('Review private-beta demand without exposing applicant details outside platform administration.')"
    />

    <div class="mt-6 flex flex-wrap items-center gap-2">
        <x-signal.ui.button :href="route($routeNames['index'])" :variant="$status ? 'secondary' : 'primary'">{{ __('All') }} ({{ $counts->sum() }})</x-signal.ui.button>
        @foreach (\App\Modules\Deployer\Models\AccessRequest::STATUSES as $item)
            <x-signal.ui.button :href="route($routeNames['index'], ['status' => $item])" :variant="$status === $item ? 'primary' : 'secondary'">{{ ucfirst($item) }} ({{ $counts[$item] ?? 0 }})</x-signal.ui.button>
        @endforeach
        <x-signal.ui.button href="{{ route($routeNames['export'], array_filter(['status' => $status])) }}" variant="secondary">{{ __('Export CSV') }}</x-signal.ui.button>
    </div>

    <x-signal.ui.insights
        id="access-request-insights"
        class="mt-6"
        :summary="$status ? __('Showing :status access requests', ['status' => ucfirst($status)]) : __('All access-request statuses')"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-signal.ui.stat
                :label="__('Total requests')"
                :value="$counts->sum()"
                :description="__('All retained access requests across statuses.')"
            />
            @foreach (\App\Modules\Deployer\Models\AccessRequest::STATUSES as $item)
                <x-signal.ui.stat
                    :label="ucfirst($item)"
                    :value="$counts[$item] ?? 0"
                    :description="__('Requests currently marked :status.', ['status' => $item])"
                />
            @endforeach
        </dl>
    </x-signal.ui.insights>

    <div class="mt-6 space-y-4">
        @forelse ($requests as $lead)
            <x-signal.ui.card class="p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-extrabold text-ink">{{ $lead->name }}</h2>
                        <p class="mt-1 text-sm text-muted"><a class="ui-link" href="mailto:{{ $lead->email }}">{{ $lead->email }}</a>@if ($lead->company) <span aria-hidden="true">·</span> {{ $lead->company }}@endif</p>
                    </div>
                    <x-signal.ui.badge :tone="match ($lead->status) { 'accepted' => 'success', 'invited' => 'accent', 'rejected' => 'danger', default => 'neutral' }">
                        {{ $lead->status }}
                    </x-signal.ui.badge>
                </div>

                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Team') }}</dt><dd class="mt-1 text-ink">{{ $lead->team_size ?: '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Plan') }}</dt><dd class="mt-1 text-ink">{{ $lead->plan ? config('billing.plans.'.$lead->plan.'.name') : '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Requested') }}</dt><dd class="mt-1 text-ink">{{ $lead->created_at->diffForHumans() }}</dd></div>
                </dl>

                <x-signal.ui.panel class="ui-panel mt-5 bg-surface-muted p-4">
                    <p class="whitespace-pre-line text-sm leading-6 text-ink">{{ $lead->use_case }}</p>
                </x-signal.ui.panel>

                @if (! $lead->accepted_at)
                    @php($reviewUrl = route($routeNames['index'], array_filter(['dialog' => 'review-access-request-'.$lead->id, 'status' => $status])))
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-muted">{{ __('Review status, notes and invitation delivery in a focused editor.') }}</p>
                        <x-signal.ui.button
                            href="{{ $reviewUrl }}"
                            data-modal-trigger="review-access-request-{{ $lead->id }}"
                            aria-controls="review-access-request-{{ $lead->id }}"
                            aria-expanded="{{ $reviewDialogOpen && $reviewDialogId === 'review-access-request-'.$lead->id ? 'true' : 'false' }}"
                            variant="secondary"
                        >{{ __('Review request') }}</x-signal.ui.button>
                    </div>
                @else
                    <p class="mt-5 text-sm font-semibold text-muted">{{ __('Invitation accepted; this onboarding record is now read-only.') }}</p>
                @endif

                @if ($lead->invitation_expires_at && ! $lead->accepted_at)
                    <p class="mt-2 text-xs text-muted">{{ __('Invitation expires :time.', ['time' => $lead->invitation_expires_at->diffForHumans()]) }}</p>
                @endif
            </x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state
                :title="__('No access requests match this filter.')"
                icon="inbox"
            />
        @endforelse
    </div>

    <div class="mt-6">{{ $requests->links() }}</div>

    @if ($editingRequest)
        <x-scenes.admin.access-request-review-dialog
            :access-request="$editingRequest"
            :open="$reviewDialogOpen"
            :route-names="$routeNames"
        />
    @endif
