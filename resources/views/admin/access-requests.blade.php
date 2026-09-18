<x-layouts.app>
    <x-layouts.partials.heading
        icon="user-add"
        :title="__('Access requests')"
        :description="__('Review private-beta demand without exposing applicant details outside platform administration.')"
    />

    <div class="mt-6 flex flex-wrap items-center gap-2">
        <a href="{{ route('admin.access-requests.index') }}" @class(['button button--primary' => ! $status, 'button button--secondary' => $status])>{{ __('All') }} ({{ $counts->sum() }})</a>
        @foreach (\App\Models\AccessRequest::STATUSES as $item)
            <a href="{{ route('admin.access-requests.index', ['status' => $item]) }}" @class(['button button--primary' => $status === $item, 'button button--secondary' => $status !== $item])>{{ ucfirst($item) }} ({{ $counts[$item] ?? 0 }})</a>
        @endforeach
        <x-ui.button href="{{ route('admin.access-requests.export', array_filter(['status' => $status])) }}" variant="secondary">{{ __('Export CSV') }}</x-ui.button>
    </div>

    <x-ui.insights
        id="access-request-insights"
        class="mt-6"
        :summary="$status ? __('Showing :status access requests', ['status' => ucfirst($status)]) : __('All access-request statuses')"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.stat
                :label="__('Total requests')"
                :value="$counts->sum()"
                :description="__('All retained access requests across statuses.')"
            />
            @foreach (\App\Models\AccessRequest::STATUSES as $item)
                <x-ui.stat
                    :label="ucfirst($item)"
                    :value="$counts[$item] ?? 0"
                    :description="__('Requests currently marked :status.', ['status' => $item])"
                />
            @endforeach
        </dl>
    </x-ui.insights>

    <div class="mt-6 space-y-4">
        @forelse ($requests as $lead)
            <x-ui.card class="p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-black text-primary">{{ $lead->name }}</h2>
                        <p class="mt-1 text-sm text-secondary"><a class="underline" href="mailto:{{ $lead->email }}">{{ $lead->email }}</a>@if ($lead->company) <span aria-hidden="true">·</span> {{ $lead->company }}@endif</p>
                    </div>
                    <x-ui.badge :tone="match ($lead->status) { 'accepted' => 'success', 'invited' => 'accent', 'rejected' => 'danger', default => 'neutral' }">
                        {{ $lead->status }}
                    </x-ui.badge>
                </div>

                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Team') }}</dt><dd class="mt-1 text-primary">{{ $lead->team_size ?: '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Plan') }}</dt><dd class="mt-1 text-primary">{{ $lead->plan ? config('billing.plans.'.$lead->plan.'.name') : '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Requested') }}</dt><dd class="mt-1 text-primary">{{ $lead->created_at->diffForHumans() }}</dd></div>
                </dl>

                <div class="mt-5 rounded-lg bg-secondary p-4">
                    <p class="whitespace-pre-line text-sm leading-6 text-primary">{{ $lead->use_case }}</p>
                </div>

                @if (! $lead->accepted_at)
                    <form method="POST" action="{{ route('admin.access-requests.update', $lead) }}" class="mt-5 grid gap-3 sm:grid-cols-[10rem_1fr_auto]">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label class="sr-only" for="access-status-{{ $lead->id }}">{{ __('Request status') }}</label>
                            <select id="access-status-{{ $lead->id }}" name="status" class="input secondary w-full rounded-lg">
                                @foreach (\App\Models\AccessRequest::STATUSES as $item)
                                    @if ($item !== 'accepted')
                                        <option value="{{ $item }}" @selected($lead->status === $item)>{{ ucfirst($item) }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="sr-only" for="review-notes-{{ $lead->id }}">{{ __('Private review note') }}</label>
                            <input id="review-notes-{{ $lead->id }}" name="review_notes" value="{{ $lead->review_notes }}" maxlength="2000" placeholder="{{ __('Private review note') }}" class="input secondary w-full rounded-lg">
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="submit" variant="primary">{{ __('Save') }}</x-ui.button>
                            @if ($lead->status === 'invited')
                                <x-ui.button type="submit" name="resend_invitation" value="1" variant="secondary">{{ __('Resend') }}</x-ui.button>
                            @endif
                        </div>
                    </form>
                @else
                    <p class="mt-5 text-sm font-semibold text-secondary">{{ __('Invitation accepted; this onboarding record is now read-only.') }}</p>
                @endif

                @if ($lead->invitation_expires_at && ! $lead->accepted_at)
                    <p class="mt-2 text-xs text-secondary">{{ __('Invitation expires :time.', ['time' => $lead->invitation_expires_at->diffForHumans()]) }}</p>
                @endif
            </x-ui.card>
        @empty
            <x-ui.empty-state
                :title="__('No access requests match this filter.')"
                icon="inbox"
            />
        @endforelse
    </div>

    <div class="mt-6">{{ $requests->links() }}</div>
</x-layouts.app>
