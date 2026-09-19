@props([
    'feedback',
    'formOld' => false,
    'open' => false,
])

@php($dialogId = 'feedback-review-'.$feedback->id)

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Review feedback')"
    :description="__('Update the workspace status and record a response without leaving the feedback list.')"
    :open="$open"
>
    <form method="POST" action="{{ route('feedback.update', $feedback) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <input type="hidden" name="_feedback_review_id" value="{{ $feedback->id }}">
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Status') }}</span>
            <select name="status" class="input secondary w-full rounded-lg">
                @foreach (\App\Models\ProductFeedback::STATUSES as $value)
                    <option value="{{ $value }}" @selected(($formOld ? old('status', $feedback->status) : $feedback->status) === $value)>{{ str($value)->headline() }}</option>
                @endforeach
            </select>
            <x-forms.errors name="status" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Workspace response') }}</span>
            <textarea name="review_response" rows="4" maxlength="10000" class="input secondary w-full rounded-lg" placeholder="{{ __('Decision, workaround, or planned resolution') }}">{{ $formOld ? old('review_response', $feedback->review_response) : $feedback->review_response }}</textarea>
            <x-forms.errors name="review_response" />
        </label>
        <x-ui.button type="submit" variant="primary">{{ __('Save review') }}</x-ui.button>
    </form>
</x-dialogs.modal>
