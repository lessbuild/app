<x-signal.layouts.core :title="__('Your workspaces')" :description="__('Choose a workspace to manage its projects.')">
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.layouts.topbar
        :navigation="[]"
        :title="__('Your workspaces')"
        product-key="core"
        :brand-url="route('core.home')"
        :projects-url="route('core.home')"
        :current-workspace="null"
        :workspace-options="$workspaces"
        workspace-switch-route="core.workspaces.select"
        workspace-manage-route="core.workspaces.index"
        :account-user="$user"
        logout-route="core.logout"
        :show-notifications="false"
        :show-project-context="false"
        :show-environment-context="false"
    />

    <main id="main-content" tabindex="-1" class="ui-layout-gutter mx-auto w-full max-w-screen-2xl py-7 sm:py-9">
        <x-alerts.flash />
        <x-signal.ui.page-header
            eyebrow="{{ __('Buildpusher') }}"
            :title="__('Your workspaces')"
            :description="__('Projects and product access are managed inside a workspace.')"
        >
            <x-slot:actions>
                @if ($canCreateWorkspace ?? false)
                    <a href="#create-workspace" class="ui-btn ui-btn-primary ui-btn-md">{{ __('Create workspace') }}</a>
                @endif
            </x-slot:actions>
        </x-signal.ui.page-header>

        @if ($workspaces->isEmpty())
            <x-signal.ui.empty-state
                :title="__('No workspace access yet')"
                :description="__('Ask a workspace owner to invite this account. Your existing product account and data remain separate until the migration mappings are reconciled.')"
                icon="user-group"
            />
        @else
            <section aria-label="{{ __('Available workspaces') }}" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($workspaces as $workspace)
                    <x-signal.ui.card tone="interactive" class="p-5">
                        <p class="ui-eyebrow">{{ __('Workspace') }}</p>
                        <h2 class="mt-2 text-lg font-extrabold text-ink">{{ $workspace->name }}</h2>
                        <a href="{{ route('core.workspace.dashboard', $workspace) }}" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-control px-3 text-sm font-extrabold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                            {{ __('Open workspace') }}
                            <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-right"></use></svg>
                        </a>
                    </x-signal.ui.card>
                @endforeach
            </section>
        @endif

        @if ($canCreateWorkspace ?? false)
            <section id="create-workspace" aria-labelledby="create-workspace-heading" class="mt-8 max-w-2xl">
                <x-signal.ui.card as="form" method="POST" :action="route('core.workspaces.store')" class="grid gap-4 p-5 sm:p-6">
                    @csrf
                    <div>
                        <h2 id="create-workspace-heading" class="text-lg font-extrabold text-ink">{{ __('Create a workspace') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-muted">{{ __('A workspace keeps your team, projects, and separate app subscriptions together.') }}</p>
                    </div>
                    <x-signal.ui.input-field name="name" :label="__('Workspace name')" :value="old('name')" :placeholder="__('Acme Studio')" required />
                    <div>
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Create workspace') }}</x-signal.ui.button>
                    </div>
                </x-signal.ui.card>
            </section>
        @elseif ($user->status !== 'active' || ! $user->hasVerifiedEmail())
            <x-signal.ui.alert tone="info" class="mt-8 max-w-2xl">
                {{ __('Verify your Buildpusher account email before creating a workspace.') }}
            </x-signal.ui.alert>
        @endif
    </main>
</x-signal.layouts.core>
