@props([
    'environment',
    'open' => false,
])

@php($dialogId = 'environment-resource-dialog-'.$environment->id)

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Attach resource')"
    :description="__('Attach a managed or external service to this environment. Secret variables are encrypted before they are stored.')"
    :open="$open"
>
    <form method="POST" action="{{ route('environments.resources.store', $environment) }}" class="grid gap-3 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="_environment_id" value="{{ $environment->id }}">
        <input type="hidden" name="_environment_panel" value="resources">
        <label>
            <span class="ui-label">{{ __('Name') }}</span>
            <input name="name" value="{{ old('name') }}" placeholder="primary-database" class="ui-input" required>
            <x-forms.errors name="name" />
        </label>
        <label>
            <span class="ui-label">{{ __('Type') }}</span>
            <select name="type" class="ui-input">
                @foreach (\App\Modules\Deployer\Models\EnvironmentResource::TYPES as $type)
                    <option value="{{ $type }}" @selected(old('type', 'mysql') === $type)>{{ str($type)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
            <x-forms.errors name="type" />
        </label>
        <label class="flex items-center gap-2 sm:col-span-2">
            <input type="hidden" name="is_managed" value="0">
            <input class="ui-check" type="checkbox" name="is_managed" value="1" @checked(old('is_managed') === '1')>
            <span class="text-sm text-ink">{{ __('Manage on attached server') }}</span>
        </label>
        <label class="sm:col-span-2">
            <span class="ui-label">{{ __('Connection variables') }}</span>
            <textarea name="variables" rows="4" placeholder="REDIS_HOST=cache.example.com&#10;REDIS_PASSWORD=…" class="ui-input font-mono">{{ old('variables') }}</textarea>
            <x-forms.errors name="variables" />
            <span class="ui-help">{{ __('Values are encrypted and are not rendered after saving.') }}</span>
        </label>
        <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Attach resource') }}</x-ui.button>
    </form>
</x-dialogs.modal>
