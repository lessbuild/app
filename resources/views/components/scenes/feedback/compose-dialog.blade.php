@props([
    'open' => false,
])

<x-dialogs.modal
    id="feedback-compose"
    :title="__('Send private feedback')"
    :description="__('Visible only to you and workspace administrators. Never include passwords, tokens, private keys, or environment values.')"
    :open="$open"
>
    <form method="POST" action="{{ route('feedback.store') }}" class="space-y-4">
        @csrf
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="feedback-category" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Type') }}</label>
                <select id="feedback-category" name="category" class="input secondary mt-2 w-full rounded-lg">
                    @foreach (\App\Models\ProductFeedback::CATEGORIES as $value)
                        <option value="{{ $value }}" @selected($value === old('category', 'bug'))>{{ str($value)->headline() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="feedback-severity" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Impact') }}</label>
                <select id="feedback-severity" name="severity" class="input secondary mt-2 w-full rounded-lg">
                    @foreach (\App\Models\ProductFeedback::SEVERITIES as $value)
                        <option value="{{ $value }}" @selected($value === old('severity', 'normal'))>{{ str($value)->headline() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div>
            <label for="feedback-title" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Summary') }}</label>
            <input id="feedback-title" name="title" maxlength="160" required value="{{ old('title') }}" class="input secondary mt-2 w-full rounded-lg" placeholder="{{ __('What happened or should change?') }}">
        </div>
        <div>
            <label for="feedback-description" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Details') }}</label>
            <textarea id="feedback-description" name="description" rows="5" maxlength="10000" required class="input secondary mt-2 w-full rounded-lg" placeholder="{{ __('Describe the outcome you expected and what you saw.') }}">{{ old('description') }}</textarea>
        </div>
        <div>
            <label for="feedback-reproduction" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Steps to reproduce (optional)') }}</label>
            <textarea id="feedback-reproduction" name="reproduction_steps" rows="4" maxlength="10000" class="input secondary mt-2 w-full rounded-lg" placeholder="1. Open…">{{ old('reproduction_steps') }}</textarea>
        </div>
        <div>
            <label for="feedback-page" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Related page (optional)') }}</label>
            <input id="feedback-page" name="page" maxlength="500" value="{{ old('page', request()->query('from')) }}" class="input secondary mt-2 w-full rounded-lg font-mono" placeholder="/projects/12">
        </div>
        <x-forms.errors name="category" />
        <x-forms.errors name="severity" />
        <x-forms.errors name="title" />
        <x-forms.errors name="description" />
        <x-forms.errors name="reproduction_steps" />
        <x-forms.errors name="page" />
        <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Submit feedback') }}</x-ui.button>
    </form>
</x-dialogs.modal>
