@props([
    'accessRequest',
    'open' => false,
    'routeNames' => [
        'index' => 'admin.access-requests.index',
        'export' => 'admin.access-requests.export',
        'update' => 'admin.access-requests.update',
    ],
])

<x-signal.overlays.modal
    id="review-access-request-{{ $accessRequest->id }}"
    :title="__('Review access request')"
    :description="__('Update the private review status and invitation for :name.', ['name' => $accessRequest->name])"
    :open="$open"
>
    <x-signal.ui.panel class="ui-panel mb-5 bg-surface-muted p-4">
        <p class="font-semibold text-ink">{{ $accessRequest->name }}</p>
        <p class="mt-1 break-all text-sm text-muted">{{ $accessRequest->email }}</p>
        @if ($accessRequest->company)
            <p class="mt-1 text-sm text-muted">{{ $accessRequest->company }}</p>
        @endif
    </x-signal.ui.panel>

    @if ($accessRequest->accepted_at)
        <p class="text-sm font-semibold text-muted">{{ __('Invitation accepted; this onboarding record is now read-only.') }}</p>
    @else
        <form method="POST" action="{{ route($routeNames['update'], $accessRequest) }}" class="space-y-5">
            @csrf
            @method('PATCH')
            <x-signal.ui.input type="hidden" name="_access_request_review" value="{{ $accessRequest->id }}" :restore="false" />

            <label class="block">
                <span class="ui-label">{{ __('Request status') }}</span>
                <x-signal.ui.select id="review-access-request-{{ $accessRequest->id }}-status" name="status" class="ui-input">
                    @foreach (\App\Modules\Deployer\Models\AccessRequest::STATUSES as $item)
                        @if ($item !== 'accepted')
                            <option value="{{ $item }}" @selected(old('status', $accessRequest->status) === $item)>{{ ucfirst($item) }}</option>
                        @endif
                    @endforeach
                </x-signal.ui.select>
                <x-forms.errors name="status" />
            </label>

            <label class="block">
                <span class="ui-label">{{ __('Private review note') }}</span>
                <x-signal.ui.textarea id="review-access-request-{{ $accessRequest->id }}-notes" name="review_notes" rows="4" maxlength="2000" placeholder="{{ __('Private review note') }}" class="ui-input" :restore="false">{{ old('review_notes', $accessRequest->review_notes) }}</x-signal.ui.textarea>
                <x-forms.errors name="review_notes" />
            </label>

            <div class="flex flex-wrap gap-2">
                <x-signal.ui.button type="submit" variant="primary">{{ __('Save review') }}</x-signal.ui.button>
                @if ($accessRequest->status === 'invited')
                    <x-signal.ui.button type="submit" name="resend_invitation" value="1" variant="secondary">{{ __('Resend invitation') }}</x-signal.ui.button>
                @endif
            </div>
        </form>
    @endif
</x-signal.overlays.modal>
