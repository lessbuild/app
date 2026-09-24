@props([
    'feedback',
    'formOld' => false,
    'open' => false,
])

@php($dialogId = 'feedback-review-'.$feedback->id)

<x-signal.overlays.modal
    :id="$dialogId"
    :title="__('Review feedback')"
    :description="__('Update the workspace status and record a response without leaving the feedback list.')"
    :open="$open"
>
    <form method="POST" action="{{ route('feedback.update', $feedback) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <x-signal.ui.input type="hidden" name="_feedback_review_id" value="{{ $feedback->id }}" :restore="false" />
        <div>
            <label class="ui-label" for="feedback-review-status-{{ $feedback->id }}">{{ __('Status') }}</label>
            <x-signal.ui.select id="feedback-review-status-{{ $feedback->id }}" name="status" class="ui-input">
                @foreach (\App\Modules\Deployer\Models\ProductFeedback::STATUSES as $value)
                    <option value="{{ $value }}" @selected(($formOld ? old('status', $feedback->status) : $feedback->status) === $value)>{{ str($value)->headline() }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="status" />
        </div>
        <div>
            <label class="ui-label" for="feedback-review-response-{{ $feedback->id }}">{{ __('Workspace response') }}</label>
            <x-signal.ui.textarea id="feedback-review-response-{{ $feedback->id }}" name="review_response" rows="4" maxlength="10000" class="ui-input" placeholder="{{ __('Decision, workaround, or planned resolution') }}" :restore="false">{{ $formOld ? old('review_response', $feedback->review_response) : $feedback->review_response }}</x-signal.ui.textarea>
            <x-forms.errors name="review_response" />
        </div>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save review') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
