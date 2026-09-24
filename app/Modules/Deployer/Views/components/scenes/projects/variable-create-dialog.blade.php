@props([
    'environment',
    'open' => false,
])

@php($dialogId = 'environment-variable-dialog-'.$environment->id)

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Add encrypted variable')"
    :description="__('Save a new version without exposing the value in page content or URLs.')"
    :open="$open"
>
    <form method="POST" action="{{ route('environments.variables.store', $environment) }}" class="space-y-3">
        @csrf
        <x-signal.ui.input type="hidden" name="_environment_id" value="{{ $environment->id }}" :restore="false" />
        <x-signal.ui.input type="hidden" name="_environment_panel" value="variables" :restore="false" />
        <label class="block">
            <span class="ui-label">{{ __('Key') }}</span>
            <x-signal.ui.input name="key" value="{{ old('key') }}" placeholder="APP_SETTING" class="ui-input font-mono" required :restore="false" />
            <x-forms.errors name="key" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Value') }}</span>
            <x-signal.ui.textarea name="value" rows="3" placeholder="{{ __('Encrypted value') }}" class="ui-input" required :restore="false"></x-signal.ui.textarea>
            <x-forms.errors name="value" />
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label>
                <span class="ui-label">{{ __('Scope') }}</span>
                <x-signal.ui.select name="scope" class="ui-input mt-1">
                    <option value="runtime" @selected(old('scope', 'runtime') === 'runtime')>{{ __('Runtime only') }}</option>
                    <option value="build" @selected(old('scope') === 'build')>{{ __('Build only') }}</option>
                    <option value="all" @selected(old('scope') === 'all')>{{ __('Build and runtime') }}</option>
                </x-signal.ui.select>
                <x-forms.errors name="scope" />
            </label>
            <label>
                <span class="ui-label">{{ __('Rotate by (optional)') }}</span>
                <x-signal.ui.input type="date" name="rotation_due_at" value="{{ old('rotation_due_at') }}" min="{{ now()->addDay()->toDateString() }}" class="ui-input mt-1" :restore="false" />
                <x-forms.errors name="rotation_due_at" />
            </label>
        </div>
        <label class="flex items-center gap-2">
            <x-signal.ui.input class="ui-check" type="checkbox" name="is_secret" value="1" @checked(old('is_secret', '1') === '1') :restore="false" />
            <span class="text-sm text-ink">{{ __('Mask as secret') }}</span>
        </label>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save new version') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
