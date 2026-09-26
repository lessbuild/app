<x-signal.layouts.platform
    :title="__('Create project')"
    :description="__('Create a workspace project that can be shared with connected applications.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Create project')"
        :description="__('Start with one shared project. Product plans and activation stay independent.')"
    />

    <div class="mx-auto max-w-3xl">
        <x-signal.ui.card class="p-5 sm:p-8">
            <form action="{{ route('core.projects.store', $workspace) }}" method="POST" class="grid gap-6">
                @csrf
                <x-signal.ui.field :label="__('Project name')" name="name" required :description="__('Choose a name your team can recognize in every app.')">
                    <x-signal.ui.input name="name" required maxlength="120" autocomplete="off" placeholder="{{ __('e.g. Storefront') }}" />
                </x-signal.ui.field>

                <x-signal.ui.field :label="__('Description')" name="description" :description="__('Optional context shared in the project directory.')">
                    <x-signal.ui.textarea name="description" maxlength="2000" rows="4" placeholder="{{ __('What is this project for?') }}" />
                </x-signal.ui.field>

                <div class="flex flex-wrap justify-end gap-2 border-t border-line pt-5">
                    <x-signal.ui.button :href="route('core.projects.index', $workspace)">{{ __('Cancel') }}</x-signal.ui.button>
                    <x-signal.ui.button variant="primary" type="submit">
                        <svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#plus"></use></svg>
                        {{ __('Create project') }}
                    </x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>

        <x-signal.ui.alert tone="info" class="mt-5">
            {{ __('Creating a project does not start a subscription or provision resources in any product.') }}
        </x-signal.ui.alert>
    </div>
</x-signal.layouts.platform>
