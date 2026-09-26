<x-signal.layouts.platform
    :title="__('Edit :project', ['project' => $project->name])"
    :description="__('Update the shared project details used by connected applications.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
    :current-project="$project"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name.' · '.__('Project settings')"
        :title="__('Edit project')"
        :description="__('Changes to the name and description appear in every connected application.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.show', [$workspace, $project])">
                {{ __('Back to project') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <div class="mx-auto grid max-w-4xl gap-5">
        <x-signal.ui.card class="p-5 sm:p-8">
            <form action="{{ route('core.projects.update', [$workspace, $project]) }}" method="POST" class="grid gap-6">
                @csrf
                @method('PUT')
                <x-signal.ui.field :label="__('Project name')" name="name" required :description="__('Choose a name your team can recognize in every app.')">
                    <x-signal.ui.input name="name" :value="$project->name" required maxlength="120" autocomplete="off" />
                </x-signal.ui.field>

                <x-signal.ui.field :label="__('Description')" name="description" :description="__('Optional context shared in the project directory and connected apps.')">
                    <x-signal.ui.textarea name="description" :value="$project->description" maxlength="2000" rows="4" />
                </x-signal.ui.field>

                <div class="flex flex-wrap justify-end gap-2 border-t border-line pt-5">
                    <x-signal.ui.button :href="route('core.projects.show', [$workspace, $project])">{{ __('Cancel') }}</x-signal.ui.button>
                    <x-signal.ui.button variant="primary" type="submit">
                        <svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#check"></use></svg>
                        {{ __('Save changes') }}
                    </x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>

        <x-signal.ui.card class="flex flex-wrap items-center justify-between gap-5 p-5 sm:p-6">
            <div class="max-w-2xl">
                <p class="ui-eyebrow">{{ __('Project lifecycle') }}</p>
                <h2 class="mt-2 text-base font-extrabold text-ink">{{ __('Archive this project') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">
                    {{ __('Archiving hides this project from active work and pauses Core-managed connections. Its app resources, separate subscriptions, and connection history are kept. Workspace owners and admins can restore it later.') }}
                </p>
            </div>
            <form action="{{ route('core.projects.archive', [$workspace, $project]) }}" method="POST" data-confirm="{{ __('Archive this project? Its app resources, subscriptions, and connection history will be preserved, and it can be restored later.') }}">
                @csrf
                <x-signal.ui.button variant="danger" type="submit">
                    {{ __('Archive project') }}
                </x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    </div>
</x-signal.layouts.platform>
