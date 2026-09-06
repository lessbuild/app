<x-layouts.app>
    <x-layouts.partials.heading :title="__('Access requests')" :description="__('Review private-beta demand without exposing applicant details outside platform administration.')" />
    <div class="mt-6 flex flex-wrap gap-2">
        <a href="{{ route('admin.access-requests.index') }}" class="button secondary">{{ __('All') }} ({{ $counts->sum() }})</a>
        @foreach(\App\Models\AccessRequest::STATUSES as $item)
            <a href="{{ route('admin.access-requests.index', ['status' => $item]) }}" class="button secondary">{{ ucfirst($item) }} ({{ $counts[$item] ?? 0 }})</a>
        @endforeach
        <a href="{{ route('admin.access-requests.export', array_filter(['status' => $status])) }}" class="button primary">{{ __('Export CSV') }}</a>
    </div>
    <div class="mt-6 space-y-4">
        @forelse($requests as $lead)
            <article class="rounded-2xl border border-primary bg-primary p-5">
                <div class="flex flex-wrap justify-between gap-3">
                    <div><h2 class="font-black text-primary">{{ $lead->name }}</h2><p class="text-sm text-secondary"><a class="underline" href="mailto:{{ $lead->email }}">{{ $lead->email }}</a>@if($lead->company) · {{ $lead->company }}@endif</p></div>
                    <span class="rounded-full bg-secondary px-3 py-1 text-xs font-bold uppercase text-secondary">{{ $lead->status }}</span>
                </div>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="font-bold text-primary">{{ __('Team') }}</dt><dd class="text-secondary">{{ $lead->team_size ?: '—' }}</dd></div>
                    <div><dt class="font-bold text-primary">{{ __('Plan') }}</dt><dd class="text-secondary">{{ $lead->plan ? config('billing.plans.'.$lead->plan.'.name') : '—' }}</dd></div>
                    <div><dt class="font-bold text-primary">{{ __('Requested') }}</dt><dd class="text-secondary">{{ $lead->created_at->diffForHumans() }}</dd></div>
                </dl>
                <p class="mt-4 whitespace-pre-line rounded-xl bg-secondary p-4 text-sm leading-6 text-primary">{{ $lead->use_case }}</p>
                @if(!$lead->accepted_at)<form method="POST" action="{{ route('admin.access-requests.update', $lead) }}" class="mt-4 grid gap-3 sm:grid-cols-[10rem_1fr_auto]">
                    @csrf @method('PATCH')
                    <select name="status" class="input secondary rounded">@foreach(\App\Models\AccessRequest::STATUSES as $item)@if($item !== 'accepted')<option value="{{ $item }}" @selected($lead->status === $item)>{{ ucfirst($item) }}</option>@endif @endforeach</select>
                    <input name="review_notes" value="{{ $lead->review_notes }}" maxlength="2000" placeholder="{{ __('Private review note') }}" class="input secondary rounded">
                    <div class="flex gap-2"><button type="submit" class="button primary">{{ __('Save') }}</button>@if($lead->status === 'invited')<button type="submit" name="resend_invitation" value="1" class="button secondary">{{ __('Resend') }}</button>@endif</div>
                </form>@else<p class="mt-4 text-sm font-semibold text-secondary">{{ __('Invitation accepted; this onboarding record is now read-only.') }}</p>@endif
                @if($lead->invitation_expires_at && !$lead->accepted_at)<p class="mt-2 text-xs text-secondary">{{ __('Invitation expires :time.', ['time' => $lead->invitation_expires_at->diffForHumans()]) }}</p>@endif
            </article>
        @empty
            <p class="rounded-xl border border-primary bg-primary p-6 text-secondary">{{ __('No access requests match this filter.') }}</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $requests->links() }}</div>
</x-layouts.app>
