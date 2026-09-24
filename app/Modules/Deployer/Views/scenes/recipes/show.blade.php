<x-layouts.app>
    @php
        $recipeEditOpen = $editDialogOpen;
        $recipeEditUrl = route('recipes.show', ['recipe' => $recipe, 'dialog' => 'edit-recipe']);
    @endphp

    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    <x-signal.ui.page-header
        :title="$recipe->name"
        :description="$recipe->description ?: __('No description')"
    >
        <x-slot:actions>
            @if ($recipe->is_published)
                <x-signal.ui.button :href="route('gallery.show', $recipe)" variant="secondary">{{ __('View in Gallery') }}</x-signal.ui.button>
            @endif
            <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
                @csrf
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Duplicate') }}</x-signal.ui.button>
            </form>
            <x-signal.ui.button
                :href="$recipeEditUrl"
                data-modal-trigger="recipe-edit-dialog"
                aria-controls="recipe-edit-dialog"
                aria-expanded="{{ $recipeEditOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="mr-2 h-4 w-4 stroke-2 text-muted">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Recipe') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.local-nav class="mt-6" :label="__('Recipe sections')">
        <a href="#recipe-overview" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#recipe-assignments" class="ui-local-nav__link">{{ __('Assignments') }}</a>
    </x-signal.ui.local-nav>

    <x-signal.ui.panel as="section" id="recipe-overview" data-recipe-section="overview" class="ui-panel mt-6 scroll-mt-24 p-4 text-sm sm:p-5">
        <p class="font-semibold text-ink">{{ __('Provisioning plan snapshots') }}</p>
        <p class="mt-1">
            {{ __('This is the current assignment map. Each server keeps the encrypted recipe plan captured when its provisioning was created, so later recipe edits or deletion do not rewrite an existing server plan.') }}
        </p>
    </x-signal.ui.panel>

    <x-signal.ui.insights
        id="recipe-insights"
        class="mt-6 scroll-mt-24"
        :summary="trans_choice(':count assigned server|:count assigned servers', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-signal.ui.stat class="ui-card" :label="__('Assigned servers')" :value="$metrics['total']" :description="__('Current recipe-to-server assignments.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Ready servers')" :value="$metrics['ready']" :description="__('Assigned servers ready for workloads.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Provisioning servers')" :value="$metrics['provisioning']" :description="__('Queued, waiting, or provisioning assignments.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Failed servers')" :value="$metrics['failed']" :description="__('Assignments requiring operator attention.')" />
        </dl>
    </x-signal.ui.insights>

    <section id="recipe-assignments" data-recipe-section="assignments" class="mt-8 scroll-mt-24" aria-labelledby="server-assignments-heading">
        <div>
            <p class="ui-eyebrow">{{ __('Provisioning coverage') }}</p>
            <h2 id="server-assignments-heading" class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Server assignments') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Order shows this recipe’s position within each server’s selected plan.') }}</p>
        </div>

        @if ($servers->isEmpty())
            <div class="mx-auto mt-6 max-w-3xl">
                <x-signal.ui.empty-state
                    :title="__('No servers use this recipe')"
                    :description="__('Select this recipe when creating a server to include it in that server’s immutable provisioning plan.')"
                />
            </div>
        @else
            <x-signal.ui.panel class="ui-panel mt-6 divide-y divide-line overflow-hidden" aria-label="{{ __('Servers assigned to this recipe') }}">
                @foreach ($servers as $server)
                    <article data-recipe-server-assignment class="p-4 transition-colors hover:bg-surface-muted sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="ui-eyebrow text-[0.65rem]">{{ __('Order #:order', ['order' => $server->pivot->position + 1]) }}</p>
                                <a href="{{ route('servers.show', $server) }}" class="mt-1 block font-semibold text-ink hover:underline">{{ $server->label }}</a>
                                @if ($server->display_name)
                                    <p class="mt-1 text-xs text-muted">{{ $server->name }}</p>
                                @endif
                            </div>
                            <x-signal.ui.badge :tone="$server->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_ACTIVE ? 'success' : ($server->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_FAILED ? 'danger' : 'info')">
                                {{ str($server->provisioning_status)->replace('_', ' ') }}
                            </x-signal.ui.badge>
                        </div>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Type') }}</dt>
                                <dd class="mt-1 text-ink">{{ str($server->type->value)->replace('-', ' ')->title() }}</dd>
                            </div>
                            <div>
                                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Address') }}</dt>
                                <dd class="mt-1 font-mono text-xs text-ink">{{ $server->public_ip ?? __('Not assigned') }}</dd>
                            </div>
                            <div>
                                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Status') }}</dt>
                                <dd class="mt-1 text-ink">{{ str($server->provisioning_status)->replace('_', ' ')->title() }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex justify-start sm:justify-end">
                            <x-signal.ui.button :href="route('servers.show', $server)" variant="secondary">{{ __('View server') }}</x-signal.ui.button>
                        </div>
                    </article>
                @endforeach
            </x-signal.ui.panel>
            <div class="py-4">{{ $servers->links() }}</div>
        @endif
    </section>
    <x-scenes.recipes.edit-dialog :recipe="$recipe" :open="$recipeEditOpen" />
</x-layouts.app>
