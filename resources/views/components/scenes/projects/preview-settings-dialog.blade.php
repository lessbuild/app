@props([
    'project',
    'open' => false,
])

<x-dialogs.modal
    id="project-preview-settings-dialog"
    :title="__('Preview environment settings')"
    :description="__('Control preview lifetime and the host name used for pull-request environments.')"
    :open="$open"
>
    <form method="POST" action="{{ route('projects.previews.update', ['project' => $project, 'dialog' => 'preview-settings']) }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PATCH')
        <input type="hidden" name="_project_form" value="previews">
        <label class="flex items-center gap-2 sm:col-span-2">
            <input type="hidden" name="preview_enabled" value="0">
            <input type="checkbox" name="preview_enabled" value="1" class="ui-check" @checked(old('preview_enabled', $project->preview_enabled))>
            <span class="text-sm text-ink">{{ __('Enable previews') }}</span>
        </label>
        <x-forms.errors name="preview_enabled" />
        <label>
            <span class="ui-label">{{ __('Lifetime') }}</span>
            <input type="number" name="preview_ttl_hours" min="1" max="720" value="{{ old('preview_ttl_hours', $project->preview_ttl_hours ?: 72) }}" class="ui-input" aria-label="{{ __('Lifetime in hours') }}" required>
            <span class="mt-1 block text-xs text-muted">{{ __('Preview stacks expire after this many hours without activity.') }}</span>
            <x-forms.errors name="preview_ttl_hours" />
        </label>
        <label>
            <span class="ui-label">{{ __('Preview host') }}</span>
            <input name="preview_domain" value="{{ old('preview_domain', $project->preview_domain) }}" placeholder="previews.example.com" class="ui-input" @required(old('preview_enabled', $project->preview_enabled))>
            <x-forms.errors name="preview_domain" />
        </label>
        <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save previews') }}</x-ui.button>
    </form>
</x-dialogs.modal>
