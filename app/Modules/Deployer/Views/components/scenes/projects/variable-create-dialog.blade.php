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
        <input type="hidden" name="_environment_id" value="{{ $environment->id }}">
        <input type="hidden" name="_environment_panel" value="variables">
        <label class="block">
            <span class="ui-label">{{ __('Key') }}</span>
            <input name="key" value="{{ old('key') }}" placeholder="APP_SETTING" class="ui-input font-mono" required>
            <x-forms.errors name="key" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Value') }}</span>
            <textarea name="value" rows="3" placeholder="{{ __('Encrypted value') }}" class="ui-input" required></textarea>
            <x-forms.errors name="value" />
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label>
                <span class="ui-label">{{ __('Scope') }}</span>
                <select name="scope" class="ui-input mt-1">
                    <option value="runtime" @selected(old('scope', 'runtime') === 'runtime')>{{ __('Runtime only') }}</option>
                    <option value="build" @selected(old('scope') === 'build')>{{ __('Build only') }}</option>
                    <option value="all" @selected(old('scope') === 'all')>{{ __('Build and runtime') }}</option>
                </select>
                <x-forms.errors name="scope" />
            </label>
            <label>
                <span class="ui-label">{{ __('Rotate by (optional)') }}</span>
                <input type="date" name="rotation_due_at" value="{{ old('rotation_due_at') }}" min="{{ now()->addDay()->toDateString() }}" class="ui-input mt-1">
                <x-forms.errors name="rotation_due_at" />
            </label>
        </div>
        <label class="flex items-center gap-2">
            <input class="ui-check" type="checkbox" name="is_secret" value="1" @checked(old('is_secret', '1') === '1')>
            <span class="text-sm text-ink">{{ __('Mask as secret') }}</span>
        </label>
        <x-ui.button type="submit" variant="primary">{{ __('Save new version') }}</x-ui.button>
    </form>
</x-dialogs.modal>
