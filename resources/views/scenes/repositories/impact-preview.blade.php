<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Repositories')"
        :route="route('repositories.index')"
    ></x-layouts.partials.breadcrumbs>

    <x-layouts.partials.heading
        :title="__('Deployment impact preview')"
        :description="__('See which enabled repository targets are affected by a changed-file set before any automatic push deployment.')"
    ></x-layouts.partials.heading>

    <section class="mt-8 rounded-xl border border-primary bg-primary p-5" aria-labelledby="impact-preview-form-heading">
        <h2 id="impact-preview-form-heading" class="font-bold text-primary">{{ __('Preview changed paths') }}</h2>
        <p class="mt-2 text-sm text-secondary">
            {{ __('This is a read-only preview. It does not create builds, dispatch jobs, contact providers or change repository settings. Paths are relative to the repository root; each enabled repository is one automatic deployment target.') }}
        </p>

        <form method="GET" action="{{ route('repositories.impact-preview') }}" class="mt-5 space-y-4">
            <div>
                <label for="changed_paths" class="block text-sm font-medium text-primary">{{ __('Changed repository paths') }}</label>
                <textarea
                    id="changed_paths"
                    name="changed_paths"
                    rows="8"
                    maxlength="{{ \App\Http\Requests\RepositoryImpactPreviewRequest::MAX_INPUT_BYTES }}"
                    class="input secondary mt-1 w-full rounded-sm font-mono"
                    placeholder="apps/storefront/resources/views/home.blade.php&#10;packages/shared/src/Client.php"
                    @disabled($pathsUnavailable || filter_var(old('changed_paths_unavailable'), FILTER_VALIDATE_BOOLEAN))
                >{{ old('changed_paths', $changedPathsInput) }}</textarea>
                <p class="mt-2 text-xs text-secondary">{{ __('Enter one safe relative path per line. At most :count paths are evaluated.', ['count' => \App\Support\RepositoryPath::MAX_CHANGED_PATHS]) }}</p>
                <x-forms.errors name="changed_paths"></x-forms.errors>
            </div>
            <label class="flex items-start gap-2 text-sm text-secondary">
                <input type="hidden" name="changed_paths_unavailable" value="0">
                <input
                    type="checkbox"
                    name="changed_paths_unavailable"
                    value="1"
                    @checked($pathsUnavailable || filter_var(old('changed_paths_unavailable'), FILTER_VALIDATE_BOOLEAN))
                >
                <span>{{ __('Changed paths are unavailable from the provider; show the conservative result.') }}</span>
            </label>
            <x-forms.errors name="changed_paths_unavailable"></x-forms.errors>
            <button type="submit" class="button primary">{{ __('Preview deployment impact') }}</button>
        </form>
    </section>

    @if ($preview)
        <section class="mt-8" aria-labelledby="impact-preview-results-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="impact-preview-results-heading" class="text-2xl font-bold text-primary">{{ __('Automatic deployment targets') }}</h2>
                    <p class="mt-1 text-sm text-secondary">
                        @if ($preview->changedPaths === null)
                            {{ __('Changed paths were unavailable, so every target remains conservative and deployable.') }}
                        @else
                            {{ trans_choice(':count changed path evaluated|:count changed paths evaluated', count($preview->changedPaths), ['count' => count($preview->changedPaths)]) }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs font-semibold">
                    <span class="rounded-full bg-green-100 px-3 py-1 text-green-700">{{ __('Affected: :count', ['count' => $preview->counts[\App\Data\RepositoryChangeImpact::AFFECTED]]) }}</span>
                    <span class="rounded-full bg-blue-100 px-3 py-1 text-blue-700">{{ __('Unaffected: :count', ['count' => $preview->counts[\App\Data\RepositoryChangeImpact::UNAFFECTED]]) }}</span>
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-amber-700">{{ __('Unknown: :count', ['count' => $preview->counts[\App\Data\RepositoryChangeImpact::UNKNOWN]]) }}</span>
                </div>
            </div>

            @if ($preview->isEmpty())
                <p class="mt-4 rounded-sm border border-primary p-4 text-sm text-secondary">{{ __('No repositories with enabled push webhooks are available in this workspace.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-primary border-y border-primary">
                        <caption class="sr-only">{{ __('Read-only automatic deployment impact results') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="py-3 pr-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Target') }}</th>
                                <th scope="col" class="px-3 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Service root') }}</th>
                                <th scope="col" class="px-3 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Configured paths') }}</th>
                                <th scope="col" class="px-3 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Impact') }}</th>
                                <th scope="col" class="py-3 pl-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Matched paths') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary">
                            @foreach ($preview->targets as $target)
                                @php
                                    $repository = $target->repository;
                                    $impact = $target->impact;
                                    $impactLabel = match ($impact->status) {
                                        \App\Data\RepositoryChangeImpact::AFFECTED => __('Affected — deploy conservatively'),
                                        \App\Data\RepositoryChangeImpact::UNAFFECTED => __('Unaffected — skip automatic deployment'),
                                        default => __('Unknown — deploy conservatively'),
                                    };
                                    $impactClass = match ($impact->status) {
                                        \App\Data\RepositoryChangeImpact::AFFECTED => 'bg-green-100 text-green-700',
                                        \App\Data\RepositoryChangeImpact::UNAFFECTED => 'bg-blue-100 text-blue-700',
                                        default => 'bg-amber-100 text-amber-700',
                                    };
                                    $pathSummary = [];
                                    if ($repository->auto_deploy_include_paths) {
                                        $pathSummary[] = __('Include: :paths', ['paths' => implode(', ', $repository->auto_deploy_include_paths)]);
                                    }
                                    if ($repository->auto_deploy_exclude_paths) {
                                        $pathSummary[] = __('Exclude: :paths', ['paths' => implode(', ', $repository->auto_deploy_exclude_paths)]);
                                    }
                                @endphp
                                <tr class="align-top">
                                    <th scope="row" class="py-3 pr-3 text-left text-sm font-medium text-primary">
                                        <a href="{{ route('repositories.show', $repository) }}" class="hover:underline">{{ $repository->name }}</a>
                                        <span class="mt-1 block text-xs font-normal text-secondary">{{ $repository->website?->name ?? __('Website unavailable') }} · {{ $repository->branch }}</span>
                                    </th>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm font-mono text-secondary">{{ $repository->deploymentRoot() }}</td>
                                    <td class="min-w-[18rem] px-3 py-3 text-xs text-secondary">{{ $pathSummary === [] ? __('Every path (no filters)') : implode(' · ', $pathSummary) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm">
                                        <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $impactClass }}">{{ $impactLabel }}</span>
                                        <span class="mt-2 block text-xs text-secondary">{{ match ($impact->reason) {
                                            'no_path_filters' => __('No path filters are configured.'),
                                            'configured_path_changed' => __('A configured path changed.'),
                                            'no_configured_path_changed' => __('No configured path changed.'),
                                            'changed_paths_unavailable' => __('The changed-file list was unavailable.'),
                                            default => __('The target could not be evaluated safely.'),
                                        } }}</span>
                                    </td>
                                    <td class="min-w-[16rem] py-3 pl-3 text-xs font-mono text-secondary">
                                        @if ($impact->matchedPaths === [])
                                            &mdash;
                                        @else
                                            <ul class="space-y-1">
                                                @foreach (array_slice($impact->matchedPaths, 0, 5) as $path)
                                                    <li class="truncate" title="{{ $path }}">{{ $path }}</li>
                                                @endforeach
                                            </ul>
                                            @if (count($impact->matchedPaths) > 5)
                                                <span class="mt-1 block">{{ __(':count more matched paths', ['count' => count($impact->matchedPaths) - 5]) }}</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</x-layouts.app>
