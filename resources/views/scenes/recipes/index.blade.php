<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Provisioning Recipes')"
        :description="__('Create reusable Bash scripts for new servers.')"
    >
        <x-slot:buttons>
            <a href="{{ route('gallery.index') }}" class="button secondary">
                {{ __('Browse Gallery') }}
            </a>
            <a href="{{ route('recipes.export', array_filter($filters, fn ($value) => $value !== null)) }}" class="button secondary">
                {{ __('Export CSV') }}
            </a>
            <a href="{{ route('recipes.create') }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Recipe') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (session('status'))
        <div class="my-4 rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    <form method="GET" action="{{ route('recipes.index') }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Name or description') }}"
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
            <div>
                <label for="usage" class="block text-xs font-semibold uppercase text-secondary">{{ __('Usage') }}</label>
                <select id="usage" name="usage" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All usage states') }}</option>
                    @foreach ($usages as $usage)
                        <option value="{{ $usage }}" @selected($filters['usage'] === $usage)>
                            {{ str($usage)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('recipes.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Matching recipes') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['total'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Recipes in this filtered view.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('In use') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['in_use'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching recipes assigned to servers.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Unused') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['unused'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching recipes without assignments.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Server assignments') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['assignments'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('All matching recipe-to-server links.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Covered servers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['servers'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Distinct servers using matching recipes.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Latest update') }}</dt>
            <dd class="mt-1 text-lg font-bold text-primary">
                {{ $metrics['latest_at']?->diffForHumans() ?? __('No matching recipe') }}
            </dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Most recently updated matching recipe.') }}</dd>
        </div>
    </dl>

    @if ($recipes->isEmpty())
        <div class="mx-auto max-w-3xl">
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No recipes match these filters') : __('You have no recipes')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('Create a recipe to automate custom setup on new servers.')"
            >
                <x-slot:button>
                    @if (array_filter($filters, fn ($value) => $value !== null))
                        <a href="{{ route('recipes.index') }}" class="button primary">{{ __('Clear filters') }}</a>
                    @else
                        <a href="{{ route('recipes.create') }}" class="button secondary">{{ __('Add Recipe') }}</a>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @else
        <div class="mt-6 overflow-x-auto">
            <table class="min-w-full divide-y divide-primary border-y border-primary">
                <thead class="bg-primary border-x border-primary">
                    <tr>
                        <th class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">{{ __('Recipe') }}</th>
                        <th class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Used by') }}</th>
                        <th class="px-3 py-3.5 text-left text-sm font-semibold text-primary">{{ __('Updated') }}</th>
                        <th class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary bg-primary">
                    @foreach ($recipes as $recipe)
                        <tr class="border-x border-primary">
                            <td class="py-4 pl-4 pr-3 text-sm sm:pl-6">
                                <a class="font-medium text-ternary" href="{{ route('recipes.show', $recipe) }}">{{ $recipe->name }}</a>
                                <p class="mt-1 max-w-xl text-secondary">{{ $recipe->description ?: __('No description') }}</p>
                                @if ($recipe->is_published)
                                    <a href="{{ route('gallery.show', $recipe) }}" class="mt-2 inline-block rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700">
                                        {{ __('Published') }}
                                    </a>
                                @endif
                                @if ($recipe->source_recipe_id)
                                    @if ($recipe->source && $recipe->hasGalleryUpdate())
                                        <a href="{{ route('gallery.show', $recipe->source) }}" class="mt-2 inline-block rounded-full bg-yellow-100 px-2 py-1 text-xs font-semibold text-yellow-800">{{ __('Gallery update available') }}</a>
                                    @elseif ($recipe->source)
                                        <a href="{{ route('gallery.show', $recipe->source) }}" class="mt-2 inline-block rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700">{{ __('Gallery copy current') }}</a>
                                    @else
                                        <span class="mt-2 inline-block rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700">{{ __('Gallery source unavailable') }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                {{ trans_choice(':count server|:count servers', $recipe->servers_count, ['count' => $recipe->servers_count]) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">{{ $recipe->updated_at->diffForHumans() }}</td>
                            <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm sm:pr-6">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('recipes.show', $recipe) }}" class="button tertiary">{{ __('View') }}</a>
                                    <a href="{{ route('recipes.edit', $recipe) }}" class="button tertiary">{{ __('Edit') }}</a>
                                    <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
                                        @csrf
                                        <button type="submit" class="button tertiary">{{ __('Duplicate') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('recipes.destroy', $recipe) }}" onsubmit="return confirm('{{ __('Delete this recipe?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button tertiary">{{ __('Delete') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-4">{{ $recipes->links() }}</div>
        </div>
    @endif
</x-layouts.app>
