<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Provisioning Recipes')"
        :description="__('Create reusable Bash scripts for new servers.')"
    >
        <x-slot:buttons>
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

    @if ($recipes->isEmpty())
        <div class="mx-auto max-w-3xl">
            <x-lists.empty
                :title="__('You have no recipes')"
                :description="__('Create a recipe to automate custom setup on new servers.')"
            >
                <x-slot:button>
                    <a href="{{ route('recipes.create') }}" class="button secondary">{{ __('Add Recipe') }}</a>
                </x-slot:button>
            </x-lists.empty>
        </div>
    @else
        <div class="overflow-x-auto">
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
                                <a class="font-medium text-ternary" href="{{ route('recipes.edit', $recipe) }}">{{ $recipe->name }}</a>
                                <p class="mt-1 max-w-xl text-secondary">{{ $recipe->description ?: __('No description') }}</p>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                {{ trans_choice(':count server|:count servers', $recipe->servers_count, ['count' => $recipe->servers_count]) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">{{ $recipe->updated_at->diffForHumans() }}</td>
                            <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm sm:pr-6">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('recipes.edit', $recipe) }}" class="button tertiary">{{ __('Edit') }}</a>
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
