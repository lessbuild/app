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
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Key') }}</span>
            <input name="key" value="{{ old('key') }}" placeholder="APP_SETTING" class="input secondary w-full rounded-lg font-mono" required>
            <x-forms.errors name="key" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Value') }}</span>
            <textarea name="value" rows="3" placeholder="{{ __('Encrypted value') }}" class="input secondary w-full rounded-lg" required></textarea>
            <x-forms.errors name="value" />
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label>
                <span class="block text-xs font-bold uppercase text-secondary">{{ __('Scope') }}</span>
                <select name="scope" class="input secondary mt-1 w-full rounded-lg">
                    <option value="runtime" @selected(old('scope', 'runtime') === 'runtime')>{{ __('Runtime only') }}</option>
                    <option value="build" @selected(old('scope') === 'build')>{{ __('Build only') }}</option>
                    <option value="all" @selected(old('scope') === 'all')>{{ __('Build and runtime') }}</option>
                </select>
                <x-forms.errors name="scope" />
            </label>
            <label>
                <span class="block text-xs font-bold uppercase text-secondary">{{ __('Rotate by (optional)') }}</span>
                <input type="date" name="rotation_due_at" value="{{ old('rotation_due_at') }}" min="{{ now()->addDay()->toDateString() }}" class="input secondary mt-1 w-full rounded-lg">
                <x-forms.errors name="rotation_due_at" />
            </label>
        </div>
        <label class="flex items-center gap-2">
            <input type="checkbox" name="is_secret" value="1" @checked(old('is_secret', '1') === '1')>
            <span class="text-sm text-secondary">{{ __('Mask as secret') }}</span>
        </label>
        <x-ui.button type="submit" variant="primary">{{ __('Save new version') }}</x-ui.button>
    </form>
</x-dialogs.modal>
