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
        <x-signal.ui.input type="hidden" name="_project_form" value="previews" :restore="false" />
        <label class="flex items-center gap-2 sm:col-span-2">
            <x-signal.ui.input type="hidden" name="preview_enabled" value="0" :restore="false" />
            <x-signal.ui.input type="checkbox" name="preview_enabled" value="1" class="ui-check" @checked(old('preview_enabled', $project->preview_enabled)) :restore="false" />
            <span class="text-sm text-ink">{{ __('Enable previews') }}</span>
        </label>
        <x-forms.errors name="preview_enabled" />
        <label>
            <span class="ui-label">{{ __('Lifetime') }}</span>
            <x-signal.ui.input type="number" name="preview_ttl_hours" min="1" max="720" value="{{ old('preview_ttl_hours', $project->preview_ttl_hours ?: 72) }}" class="ui-input" aria-label="{{ __('Lifetime in hours') }}" required :restore="false" />
            <span class="mt-1 block text-xs text-muted">{{ __('Preview stacks expire after this many hours without activity.') }}</span>
            <x-forms.errors name="preview_ttl_hours" />
        </label>
        <label>
            <span class="ui-label">{{ __('Preview host') }}</span>
            <x-signal.ui.input name="preview_domain" value="{{ old('preview_domain', $project->preview_domain) }}" placeholder="previews.example.com" class="ui-input" @required(old('preview_enabled', $project->preview_enabled)) :restore="false" />
            <x-forms.errors name="preview_domain" />
        </label>
        <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save previews') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
