<x-layouts.app>
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
            <x-ui.button :href="route('recipes.edit', $recipe)" variant="primary">
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
            <x-ui.card class="mt-6 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-primary">
                    <caption class="sr-only">{{ __('Servers assigned to this recipe') }}</caption>
                    <thead class="bg-secondary">
                        <tr>
                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">{{ __('Order') }}</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Server') }}</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Type') }}</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Address') }}</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Status') }}</th>
                            <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary bg-primary">
                        @foreach ($servers as $server)
                            <tr>
                                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-primary sm:pl-6">
                                    #{{ $server->pivot->position + 1 }}
                                </td>
                                <td class="px-3 py-4 text-sm">
                                    <a href="{{ route('servers.show', $server) }}" class="font-medium text-ternary">{{ $server->label }}</a>
                                    @if ($server->display_name)
                                        <p class="mt-1 text-xs text-secondary">{{ $server->name }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                    {{ str($server->type->value)->replace('-', ' ')->title() }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                    {{ $server->public_ip ?? __('Not assigned') }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <x-ui.badge :tone="$server->provisioning_status === \App\Models\Server::STATUS_ACTIVE ? 'success' : ($server->provisioning_status === \App\Models\Server::STATUS_FAILED ? 'danger' : 'info')">
                                        {{ str($server->provisioning_status)->replace('_', ' ') }}
                                    </x-ui.badge>
                                </td>
                                <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm sm:pr-6">
                                    <a href="{{ route('servers.show', $server) }}" class="font-medium text-ternary">{{ __('View server') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </x-ui.card>
            <div class="py-4">{{ $servers->links() }}</div>
        @endif
    </section>
</x-layouts.app>
