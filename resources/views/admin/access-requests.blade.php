@php($labels = ['pending' => __('Waiting'), 'contacted' => __('Contacted'), 'invited' => __('Invited'), 'accepted' => __('Signed up'), 'declined' => __('Declined')])
<x-signal.layouts.admin :title="__('Access requests')" :description="$registrationOpen ? __('Registration is open (REGISTRATION_OPEN), so new requests only come from the old form.') : __('Registration is by invitation. Inviting someone emails them a one-time sign-up link.')">
    <x-signal.ui.local-nav :label="__('Statuses')">
        @foreach ($labels as $key => $label)
            <a href="{{ route('admin.access-requests', ['status' => $key]) }}" class="ui-local-nav__link" @if ($status === $key) aria-current="page" @endif>{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
        @endforeach
    </x-signal.ui.local-nav>
    @error('status')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror

    @forelse ($requests as $request)
        <x-signal.ui.settings-section :title="$request->name.' · '.$request->email" :description="($request->company ?? __('No company')).' · '.__('team of :size', ['size' => $request->team_size ?? '?']).' · '.$request->created_at?->diffForHumans()">
            <div class="grid gap-3 p-4 text-sm sm:p-6">
                <p class="whitespace-pre-line">{{ $request->use_case }}</p>
                @if ($request->reviewed_at)<p class="text-xs text-muted">{{ __('Reviewed by :name :when', ['name' => $request->reviewer->name ?? __('someone'), 'when' => $request->reviewed_at->diffForHumans()]) }}@if ($request->invitation_expires_at) · {{ __('invitation expires :when', ['when' => $request->invitation_expires_at->diffForHumans()]) }}@endif</p>@endif
                @if ($request->status !== 'accepted')
                    <form method="POST" action="{{ route('admin.access-requests.update', $request->id) }}" class="grid gap-3 sm:grid-cols-3">
                        @csrf
                        @method('PUT')
                        <x-signal.ui.select-field :id="'status-'.$request->id" name="status" :label="__('Status')">
                            @foreach (['pending', 'contacted', 'invited', 'declined'] as $option)
                                <option value="{{ $option }}" @selected($request->status === $option)>{{ $labels[$option] }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <div class="sm:col-span-2"><x-signal.ui.input-field :id="'notes-'.$request->id" name="review_notes" :label="__('Notes (admins only)')" :value="$request->review_notes" maxlength="2000" :restore="false" /></div>
                        <div class="flex flex-wrap items-center gap-3 sm:col-span-3">
                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                            @if ($request->status === 'invited')<x-signal.ui.checkbox :id="'resend-'.$request->id" name="resend" value="1" :restore="false">{{ __('Send a new invitation') }}</x-signal.ui.checkbox>@endif
                        </div>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>
    @empty
        <x-signal.ui.empty-state icon="inbox" :title="__('Nothing here')" :description="__('No requests with this status.')" />
    @endforelse
    {{ $requests->links() }}
</x-signal.layouts.admin>
