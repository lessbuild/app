@props([
    'accessRequest',
    'open' => false,
])

<x-dialogs.modal
    id="review-access-request-{{ $accessRequest->id }}"
    :title="__('Review access request')"
    :description="__('Update the private review status and invitation for :name.', ['name' => $accessRequest->name])"
    :open="$open"
>
    <div class="mb-5 rounded-lg border border-primary bg-secondary p-4">
        <p class="font-semibold text-primary">{{ $accessRequest->name }}</p>
        <p class="mt-1 break-all text-sm text-secondary">{{ $accessRequest->email }}</p>
        @if ($accessRequest->company)
            <p class="mt-1 text-sm text-secondary">{{ $accessRequest->company }}</p>
        @endif
    </div>

    @if ($accessRequest->accepted_at)
        <p class="text-sm font-semibold text-secondary">{{ __('Invitation accepted; this onboarding record is now read-only.') }}</p>
    @else
        <form method="POST" action="{{ route('admin.access-requests.update', $accessRequest) }}" class="space-y-5">
            @csrf
            @method('PATCH')
            <input type="hidden" name="_access_request_review" value="{{ $accessRequest->id }}">

            <label class="block">
                <span class="mb-1 block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Request status') }}</span>
                <select id="review-access-request-{{ $accessRequest->id }}-status" name="status" class="input secondary w-full rounded-lg">
                    @foreach (\App\Models\AccessRequest::STATUSES as $item)
                        @if ($item !== 'accepted')
                            <option value="{{ $item }}" @selected(old('status', $accessRequest->status) === $item)>{{ ucfirst($item) }}</option>
                        @endif
                    @endforeach
                </select>
                <x-forms.errors name="status" />
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Private review note') }}</span>
                <textarea id="review-access-request-{{ $accessRequest->id }}-notes" name="review_notes" rows="4" maxlength="2000" placeholder="{{ __('Private review note') }}" class="input secondary w-full rounded-lg">{{ old('review_notes', $accessRequest->review_notes) }}</textarea>
                <x-forms.errors name="review_notes" />
            </label>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="primary">{{ __('Save review') }}</x-ui.button>
                @if ($accessRequest->status === 'invited')
                    <x-ui.button type="submit" name="resend_invitation" value="1" variant="secondary">{{ __('Resend invitation') }}</x-ui.button>
                @endif
            </div>
        </form>
    @endif
</x-dialogs.modal>
