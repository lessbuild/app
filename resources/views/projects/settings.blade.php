@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Project settings')">
    <x-signal.ui.settings-section :title="__('Details')" :description="__('The name and description your teammates see.')">
        <form method="POST" action="{{ route('projects.update', $project) }}" class="grid gap-5 p-4 sm:p-6">
            @csrf
            @method('PUT')
            <x-signal.ui.input-field name="name" :label="__('Project name')" :value="$project->name" maxlength="100" required />
            <x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$project->description" maxlength="500" rows="3" />
            <p class="text-xs text-muted">{{ __('Project ID: :id · Slug: :slug', ['id' => $project->id, 'slug' => $project->slug]) }}</p>
            <div><x-signal.ui.button type="submit" variant="primary">{{ __('Save') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>

    <div id="environments" class="scroll-mt-24">
    <x-signal.ui.settings-section :title="__('Environments')" :description="__('Production is created with the project and can’t be removed. Add staging, development or preview environments as you need them.')">
        <ul class="divide-y divide-line" aria-label="{{ __('Environments') }}">
            @foreach ($overview->environments as $environment)
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-bold text-ink">{{ $environment->name }}</span>
                        <x-signal.ui.badge>{{ $environment->kind->label() }}</x-signal.ui.badge>
                    </span>
                    @if ($environment->kind !== \App\Enums\EnvironmentKind::Production)
                        <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="remove-environment-{{ $environment->id }}">{{ __('Remove') }}</x-signal.ui.button>
                        <x-signal.overlays.delete-confirmation
                            :id="'remove-environment-'.$environment->id"
                            :route="route('projects.environments.destroy', [$project, $environment->id])"
                            :title="__('Remove :environment?', ['environment' => $environment->name])"
                            :description="__('Services stop using this environment.')"
                            :submit-label="__('Remove')"
                        />
                    @endif
                </li>
            @endforeach
        </ul>
        <x-slot:footer>
            <form method="POST" action="{{ route('projects.environments.store', $project) }}" class="grid gap-4 p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end sm:px-6">
                @csrf
                <x-signal.ui.input-field name="name" id="environment-name" :label="__('New environment')" maxlength="60" required error-bag="environment" :placeholder="__('Staging')" />
                <x-signal.ui.select-field name="kind" id="environment-kind" :label="__('Kind')" required>
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind->value }}" @selected(old('kind') === $kind->value)>{{ $kind->label() }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Add') }}</x-signal.ui.button>
            </form>
            <form method="POST" action="{{ route('projects.environments.clone', $project) }}" class="grid gap-4 border-t border-line p-4 sm:grid-cols-[12rem_minmax(0,1fr)_12rem_auto] sm:items-end sm:px-6">
                @csrf
                <x-signal.ui.select-field name="source_id" id="clone-source" :label="__('Copy from')" required error-bag="cloneEnvironment">
                    @foreach ($project->environments()->orderBy('name')->get() as $environment)
                        <option value="{{ $environment->id }}">{{ $environment->name }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.input-field name="name" id="clone-name" :label="__('New environment')" maxlength="60" required error-bag="cloneEnvironment" :placeholder="__('Staging')" />
                <x-signal.ui.select-field name="kind" id="clone-kind" :label="__('Kind')" required>
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Clone') }}</x-signal.ui.button>
                <div class="sm:col-span-4"><x-signal.ui.checkbox name="copy_secrets" value="1" :description="__('Copies deploy settings, workers, recipes and variables; schedules come across switched off. Leave this unticked to set new secrets for it.')">{{ __('Copy secret values too') }}</x-signal.ui.checkbox></div>
            </form>
        </x-slot:footer>
    </x-signal.ui.settings-section>
    </div>

    <x-signal.ui.settings-section :title="__('Delete this project')" :description="__('Deletes its environments and service settings. Services remove their data for this project. This can’t be undone.')">
        <form method="POST" action="{{ route('projects.destroy', $project) }}" class="grid gap-4 p-4 sm:p-6">
            @csrf
            @method('DELETE')
            <x-signal.ui.input-field name="confirm_name" :label="__('Type :name to confirm', ['name' => $project->name])" autocomplete="off" required error-bag="deleteProject" :restore="false" />
            <div><x-signal.ui.button type="submit" variant="danger">{{ __('Delete :name', ['name' => $project->name]) }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>
</x-signal.layouts.project>
