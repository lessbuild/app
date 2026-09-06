<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    <x-layouts.partials.heading
        :title="$recipe->name"
        :description="$recipe->description ?: __('No description')"
    >
        <x-slot:buttons>
            @if ($recipe->is_published)
                <a href="{{ route('gallery.show', $recipe) }}" class="button secondary">{{ __('View in Gallery') }}</a>
            @endif
            <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
                @csrf
                <button type="submit" class="button secondary">{{ __('Duplicate') }}</button>
            </form>
            <a href="{{ route('recipes.edit', $recipe) }}" class="button primary">
                <svg class="mr-2 h-4 w-4 stroke-2 text-secondary">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Recipe') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <div class="mt-6 rounded-sm border border-primary bg-primary p-4 text-sm text-secondary">
        <p class="font-medium text-primary">{{ __('Provisioning plan snapshots') }}</p>
        <p class="mt-1">
            {{ __('This is the current assignment map. Each server keeps the encrypted recipe plan captured when its provisioning was created, so later recipe edits or deletion do not rewrite an existing server plan.') }}
        </p>
    </div>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Assigned servers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['total'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Current recipe-to-server assignments.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Ready servers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['ready'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Assigned servers ready for workloads.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Provisioning servers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['provisioning'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Queued, waiting, or provisioning assignments.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Failed servers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['failed'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Assignments requiring operator attention.') }}</dd>
        </div>
    </dl>

    <section class="mt-8" aria-labelledby="server-assignments-heading">
        <div>
            <h2 id="server-assignments-heading" class="text-2xl font-bold text-primary">{{ __('Server assignments') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Order shows this recipe’s position within each server’s selected plan.') }}</p>
        </div>

        @if ($servers->isEmpty())
            <div class="mx-auto mt-6 max-w-3xl">
                <x-lists.empty
                    :title="__('No servers use this recipe')"
                    :description="__('Select this recipe when creating a server to include it in that server’s immutable provisioning plan.')"
                />
            </div>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full divide-y divide-primary border-y border-primary">
                    <thead class="border-x border-primary bg-primary">
                        <tr>
                            <th class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">{{ __('Order') }}</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Server') }}</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Type') }}</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Address') }}</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Status') }}</th>
                            <th class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary bg-primary">
                        @foreach ($servers as $server)
                            <tr class="border-x border-primary">
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
                                    <span @class([
                                        'rounded-full px-3 py-1 text-xs font-semibold uppercase',
                                        'bg-green-100 text-green-700' => $server->provisioning_status === \App\Models\Server::STATUS_ACTIVE,
                                        'bg-red-100 text-red-700' => $server->provisioning_status === \App\Models\Server::STATUS_FAILED,
                                        'bg-blue-100 text-blue-700' => ! in_array($server->provisioning_status, [\App\Models\Server::STATUS_ACTIVE, \App\Models\Server::STATUS_FAILED], true),
                                    ])>{{ str($server->provisioning_status)->replace('_', ' ') }}</span>
                                </td>
                                <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm sm:pr-6">
                                    <a href="{{ route('servers.show', $server) }}" class="font-medium text-ternary">{{ __('View server') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="py-4">{{ $servers->links() }}</div>
        @endif
    </section>
</x-layouts.app>
