<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :route="route('gallery.show', $recipe)"
        :title="__('Back to gallery recipe')"
    />

    <x-layouts.partials.heading
        :title="__('Review gallery changes')"
        :description="$recipe->name"
    >
        <x-slot:buttons>
            <a href="{{ route('recipes.edit', $copy) }}" class="button secondary">{{ __('Edit My Copy') }}</a>
            @if ($copy->hasGalleryUpdate() && ! $copy->is_published)
                <form method="POST" action="{{ route('recipes.gallery.refresh', $copy) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Replace :recipe with this reviewed gallery version?', ['recipe' => $copy->name])) }})">
                    @csrf
                    <button type="submit" class="button primary">{{ __('Update Private Copy') }}</button>
                </form>
            @endif
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <div class="mt-6 rounded border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800">
        <p class="font-semibold">{{ __('Review every changed command') }}</p>
        <p class="mt-1">{{ __('The left side is your encrypted private snapshot. The right side is the contributor’s current gallery version. No script is executed from this page.') }}</p>
    </div>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Script') }}</dt>
            <dd class="mt-1 font-semibold text-primary">{{ $comparison['script_changed'] ? __('Changed') : __('Unchanged') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Name') }}</dt>
            <dd class="mt-1 font-semibold text-primary">{{ $comparison['name_changed'] ? __('Changed') : __('Unchanged') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Description') }}</dt>
            <dd class="mt-1 font-semibold text-primary">{{ $comparison['description_changed'] ? __('Changed') : __('Unchanged') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Contributor') }}</dt>
            <dd class="mt-1 font-semibold text-primary">{{ $recipe->user->name }}</dd>
        </div>
    </dl>

    <div class="mt-6 overflow-x-auto rounded-lg border border-primary">
        <table class="min-w-full divide-y divide-primary bg-primary text-sm">
            <thead>
                <tr>
                    <th scope="col" class="w-40 px-4 py-3 text-left font-semibold text-secondary">{{ __('Metadata') }}</th>
                    <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('My private copy') }}</th>
                    <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Current gallery version') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary">
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Name') }}</th>
                    <td class="px-4 py-3 text-primary">{{ $copy->name }}</td>
                    <td class="px-4 py-3 text-primary">{{ $recipe->name }}</td>
                </tr>
                <tr class="align-top">
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Description') }}</th>
                    <td class="whitespace-pre-wrap px-4 py-3 text-primary">{{ $copy->description ?: __('No description') }}</td>
                    <td class="whitespace-pre-wrap px-4 py-3 text-primary">{{ $recipe->description ?: __('No description') }}</td>
                </tr>
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Lines') }}</th>
                    <td class="px-4 py-3 text-primary">{{ $comparison['current_lines'] }}</td>
                    <td class="px-4 py-3 text-primary">{{ $comparison['gallery_lines'] }}</td>
                </tr>
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Revision') }}</th>
                    <td class="px-4 py-3 text-primary">{{ $copy->source_revision_at?->format('Y-m-d H:i:s T') ?? __('Unknown') }}</td>
                    <td class="px-4 py-3 text-primary">{{ $recipe->gallery_revision_at?->format('Y-m-d H:i:s T') ?? __('Unknown') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-2">
        <section class="min-w-0 rounded-lg border border-primary bg-primary p-5" aria-labelledby="private-script-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="private-script-heading" class="text-lg font-bold text-primary">{{ __('My private copy') }}</h2>
                <span class="text-xs text-secondary">{{ trans_choice(':count line|:count lines', $comparison['current_lines'], ['count' => $comparison['current_lines']]) }}</span>
            </div>
            <pre class="mt-3 overflow-x-auto rounded bg-gray-950 p-4 text-sm text-gray-100"><code>{{ $copy->script }}</code></pre>
        </section>
        <section class="min-w-0 rounded-lg border border-primary bg-primary p-5" aria-labelledby="gallery-script-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="gallery-script-heading" class="text-lg font-bold text-primary">{{ __('Current gallery version') }}</h2>
                <span class="text-xs text-secondary">{{ trans_choice(':count line|:count lines', $comparison['gallery_lines'], ['count' => $comparison['gallery_lines']]) }}</span>
            </div>
            <pre class="mt-3 overflow-x-auto rounded bg-gray-950 p-4 text-sm text-gray-100"><code>{{ $recipe->script }}</code></pre>
        </section>
    </div>
</x-layouts.app>
