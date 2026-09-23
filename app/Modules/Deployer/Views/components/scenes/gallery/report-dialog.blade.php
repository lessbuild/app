@props([
    'currentReport' => null,
    'open' => false,
    'recipe',
])

<x-dialogs.modal
    id="gallery-report-dialog"
    :title="$currentReport ? __('Update your private report') : __('Report a recipe issue')"
    :description="__('Your identity is not shown to the recipe contributor.')"
    :open="$open"
>
    <form method="POST" action="{{ route('gallery.report.store', $recipe) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_gallery_report_form" value="1">
        <div>
            <label for="reason" class="ui-label">{{ __('Issue type') }}</label>
            <select id="reason" name="reason" class="ui-input mt-2 w-full" required>
                <option value="">{{ __('Choose an issue') }}</option>
                @foreach (\App\Modules\Deployer\Models\RecipeReport::REASONS as $reason)
                    <option value="{{ $reason }}" @selected(old('reason', $currentReport?->reason) === $reason)>{{ str($reason)->headline() }}</option>
                @endforeach
            </select>
            <x-forms.errors name="reason" />
        </div>
        <div>
            <label for="details" class="ui-label">{{ __('Details (optional)') }}</label>
            <textarea id="details" name="details" rows="4" maxlength="1000" class="ui-input mt-2 w-full" placeholder="{{ __('Explain what the contributor should review.') }}">{{ old('details', $currentReport?->details) }}</textarea>
            <x-forms.errors name="details" />
        </div>
        <x-ui.button type="submit" variant="primary">{{ $currentReport ? __('Update Report') : __('Submit Report') }}</x-ui.button>
    </form>
</x-dialogs.modal>
