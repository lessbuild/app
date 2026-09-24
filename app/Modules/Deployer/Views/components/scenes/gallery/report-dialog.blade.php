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
        <x-signal.ui.input type="hidden" name="_gallery_report_form" value="1" :restore="false" />
        <div>
            <label for="reason" class="ui-label">{{ __('Issue type') }}</label>
            <x-signal.ui.select id="reason" name="reason" class="ui-input mt-2 w-full" required>
                <option value="">{{ __('Choose an issue') }}</option>
                @foreach (\App\Modules\Deployer\Models\RecipeReport::REASONS as $reason)
                    <option value="{{ $reason }}" @selected(old('reason', $currentReport?->reason) === $reason)>{{ str($reason)->headline() }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="reason" />
        </div>
        <div>
            <label for="details" class="ui-label">{{ __('Details (optional)') }}</label>
            <x-signal.ui.textarea id="details" name="details" rows="4" maxlength="1000" class="ui-input mt-2 w-full" placeholder="{{ __('Explain what the contributor should review.') }}" :restore="false">{{ old('details', $currentReport?->details) }}</x-signal.ui.textarea>
            <x-forms.errors name="details" />
        </div>
        <x-signal.ui.button type="submit" variant="primary">{{ $currentReport ? __('Update Report') : __('Submit Report') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
