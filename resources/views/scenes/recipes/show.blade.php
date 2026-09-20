<x-layouts.app>
    @php
        $recipeEditOpen = $editDialogOpen;
        $recipeEditUrl = route('recipes.show', ['recipe' => $recipe, 'dialog' => 'edit-recipe']);
    @endphp

    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    <x-layouts.partials.heading
        :title="$recipe->name"
        :description="$recipe->description ?: __('No description')"
    >
        <x-slot:buttons>
            @if ($recipe->is_published)
                <x-ui.button :href="route('gallery.show', $recipe)" variant="secondary">{{ __('View in Gallery') }}</x-ui.button>
            @endif
            <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">{{ __('Duplicate') }}</x-ui.button>
            </form>
            <x-ui.button
                :href="$recipeEditUrl"
                data-modal-trigger="recipe-edit-dialog"
                aria-controls="recipe-edit-dialog"
                aria-expanded="{{ $recipeEditOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="mr-2 h-4 w-4 stroke-2 text-secondary">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Recipe') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-ui.card class="mt-6 p-4 text-sm text-secondary">
        <p class="font-semibold text-primary">{{ __('Provisioning plan snapshots') }}</p>
        <p class="mt-1">
            {{ __('This is the current assignment map. Each server keeps the encrypted recipe plan captured when its provisioning was created, so later recipe edits or deletion do not rewrite an existing server plan.') }}
        </p>
    </x-ui.card>

    <x-ui.insights
        id="recipe-insights"
        class="mt-6"
        :summary="trans_choice(':count assigned server|:count assigned servers', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat class="ui-card" :label="__('Assigned servers')" :value="$metrics['total']" :description="__('Current recipe-to-server assignments.')" />
            <x-ui.stat class="ui-card" :label="__('Ready servers')" :value="$metrics['ready']" :description="__('Assigned servers ready for workloads.')" />
            <x-ui.stat class="ui-card" :label="__('Provisioning servers')" :value="$metrics['provisioning']" :description="__('Queued, waiting, or provisioning assignments.')" />
            <x-ui.stat class="ui-card" :label="__('Failed servers')" :value="$metrics['failed']" :description="__('Assignments requiring operator attention.')" />
        </dl>
    </x-ui.insights>

    <section class="mt-8" aria-labelledby="server-assignments-heading">
        <div>
            <h2 id="server-assignments-heading" class="text-2xl font-bold text-primary">{{ __('Server assignments') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Order shows this recipe’s position within each server’s selected plan.') }}</p>
        </div>

        @if ($servers->isEmpty())
            <div class="mx-auto mt-6 max-w-3xl">
                <x-ui.empty-state
                    :title="__('No servers use this recipe')"
                    :description="__('Select this recipe when creating a server to include it in that server’s immutable provisioning plan.')"
                />
            </div>
        @else
            <x-ui.card class="mt-6 divide-y divide-primary overflow-hidden" aria-label="{{ __('Servers assigned to this recipe') }}">
                @foreach ($servers as $server)
                    <article data-recipe-server-assignment class="p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Order #:order', ['order' => $server->pivot->position + 1]) }}</p>
                                <a href="{{ route('servers.show', $server) }}" class="mt-1 block font-semibold text-primary hover:underline">{{ $server->label }}</a>
                                @if ($server->display_name)
                                    <p class="mt-1 text-xs text-secondary">{{ $server->name }}</p>
                                @endif
                            </div>
                            <x-ui.badge :tone="$server->provisioning_status === \App\Models\Server::STATUS_ACTIVE ? 'success' : ($server->provisioning_status === \App\Models\Server::STATUS_FAILED ? 'danger' : 'info')">
                                {{ str($server->provisioning_status)->replace('_', ' ') }}
                            </x-ui.badge>
                        </div>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Type') }}</dt>
                                <dd class="mt-1 text-primary">{{ str($server->type->value)->replace('-', ' ')->title() }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Address') }}</dt>
                                <dd class="mt-1 font-mono text-xs text-primary">{{ $server->public_ip ?? __('Not assigned') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Status') }}</dt>
                                <dd class="mt-1 text-primary">{{ str($server->provisioning_status)->replace('_', ' ')->title() }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex justify-start sm:justify-end">
                            <x-ui.button :href="route('servers.show', $server)" variant="secondary">{{ __('View server') }}</x-ui.button>
                        </div>
                    </article>
                @endforeach
            </x-ui.card>
            <div class="py-4">{{ $servers->links() }}</div>
        @endif
    </section>
    <x-scenes.recipes.edit-dialog :recipe="$recipe" :open="$recipeEditOpen" />
</x-layouts.app>
