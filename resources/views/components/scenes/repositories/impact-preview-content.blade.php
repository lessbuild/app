@php
    $fragment = $fragment ?? false;
    $impactPreviewAction = route('repositories.impact-preview', $fragment ? ['fragment' => 'repository-impact-preview'] : []);
    $impactPreviewFragmentAction = $fragment ? $impactPreviewAction : null;
@endphp

<div data-repository-impact-preview-content class="space-y-6">
    <x-ui.card class="p-5" aria-labelledby="impact-preview-form-heading">
        <h2 id="impact-preview-form-heading" class="font-bold text-primary">{{ __('Preview changed paths') }}</h2>
        <p class="mt-2 text-sm text-secondary">
            {{ __('This is a read-only preview. It does not create builds, dispatch jobs, contact providers or change repository settings. Paths are relative to the repository root; each enabled repository is one automatic deployment target.') }}
        </p>

        <form
            method="GET"
            action="{{ $impactPreviewAction }}"
            class="mt-5 space-y-4"
            @if ($impactPreviewFragmentAction)
                data-modal-fragment-form
                data-modal-fragment-url="{{ $impactPreviewFragmentAction }}"
            @endif
        >
            <div>
                <label for="changed_paths" class="block text-sm font-medium text-primary">{{ __('Changed repository paths') }}</label>
                <textarea
                    id="changed_paths"
                    name="changed_paths"
                    rows="8"
                    maxlength="{{ \App\Http\Requests\RepositoryImpactPreviewRequest::MAX_INPUT_BYTES }}"
                    class="input secondary mt-2 min-h-[12rem] w-full rounded-lg font-mono"
                    placeholder="apps/storefront/resources/views/home.blade.php&#10;packages/shared/src/Client.php"
                    @disabled($pathsUnavailable || filter_var(old('changed_paths_unavailable'), FILTER_VALIDATE_BOOLEAN))
                >{{ old('changed_paths', $changedPathsInput) }}</textarea>
                <p class="mt-2 text-xs text-secondary">{{ __('Enter one safe relative path per line. At most :count paths are evaluated.', ['count' => \App\Support\RepositoryPath::MAX_CHANGED_PATHS]) }}</p>
                <x-forms.errors name="changed_paths" />
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
            <x-forms.errors name="changed_paths_unavailable" />
            <x-ui.button type="submit" variant="primary">{{ __('Preview deployment impact') }}</x-ui.button>
        </form>
    </x-ui.card>

    @if ($preview)
        <section aria-labelledby="impact-preview-results-heading">
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
                    <x-ui.badge tone="success">{{ __('Affected: :count', ['count' => $preview->counts[\App\Data\RepositoryChangeImpact::AFFECTED]]) }}</x-ui.badge>
                    <x-ui.badge tone="accent">{{ __('Unaffected: :count', ['count' => $preview->counts[\App\Data\RepositoryChangeImpact::UNAFFECTED]]) }}</x-ui.badge>
                    <x-ui.badge tone="warning">{{ __('Unknown: :count', ['count' => $preview->counts[\App\Data\RepositoryChangeImpact::UNKNOWN]]) }}</x-ui.badge>
                </div>
            </div>

            <x-ui.insights
                id="repository-impact-insights"
                class="mt-5"
                :summary="$preview->changedPaths === null ? __('Conservative result because changed paths are unavailable') : __('Read-only path impact summary')"
            >
                <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.stat
                        :label="__('Targets evaluated')"
                        :value="count($preview->targets)"
                        :description="__('Enabled automatic deployment targets in this workspace.')"
                    />
                    <x-ui.stat
                        :label="__('Affected')"
                        :value="$preview->counts[\App\Data\RepositoryChangeImpact::AFFECTED]"
                        :description="__('A configured path changed and deployment remains conservative.')"
                    />
                    <x-ui.stat
                        :label="__('Unaffected')"
                        :value="$preview->counts[\App\Data\RepositoryChangeImpact::UNAFFECTED]"
                        :description="__('No configured path matched the supplied changes.')"
                    />
                    <x-ui.stat
                        :label="__('Conservative targets')"
                        :value="$preview->counts[\App\Data\RepositoryChangeImpact::AFFECTED] + $preview->counts[\App\Data\RepositoryChangeImpact::UNKNOWN]"
                        :description="__('Affected or unknown targets that should not be skipped automatically.')"
                    />
                </dl>
            </x-ui.insights>

            @if ($preview->isEmpty())
                <x-ui.empty-state
                    class="mt-4"
                    icon="information-circle"
                    :title="__('No repositories with enabled push webhooks are available in this workspace.')"
                />
            @else
                <div class="ui-card mt-4 divide-y divide-primary overflow-hidden" aria-label="{{ __('Read-only automatic deployment impact results') }}">
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
                        <article data-impact-target class="p-4 sm:p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('repositories.show', $repository) }}" class="font-semibold text-primary hover:underline">{{ $repository->name }}</a>
                                    <p class="mt-1 text-xs text-secondary">{{ $repository->website?->name ?? __('Website unavailable') }} · {{ $repository->branch }}</p>
                                </div>
                                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $impactClass }}">{{ $impactLabel }}</span>
                            </div>
                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                <div>
                                    <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Service root') }}</dt>
                                    <dd class="mt-1 font-mono text-xs text-primary">{{ $repository->deploymentRoot() }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Configured paths') }}</dt>
                                    <dd class="mt-1 text-secondary">{{ $pathSummary === [] ? __('Every path (no filters)') : implode(' · ', $pathSummary) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Reason') }}</dt>
                                    <dd class="mt-1 text-secondary">{{ match ($impact->reason) {
                                        'no_path_filters' => __('No path filters are configured.'),
                                        'configured_path_changed' => __('A configured path changed.'),
                                        'no_configured_path_changed' => __('No configured path changed.'),
                                        'changed_paths_unavailable' => __('The changed-file list was unavailable.'),
                                        default => __('The target could not be evaluated safely.'),
                                    } }}</dd>
                                </div>
                            </dl>
                            <div class="mt-4 rounded-lg bg-secondary p-3">
                                <h3 class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Matched paths') }}</h3>
                                @if ($impact->matchedPaths === [])
                                    <p class="mt-2 text-sm text-secondary">&mdash;</p>
                                @else
                                    <ul class="mt-2 space-y-1 font-mono text-xs text-secondary">
                                        @foreach (array_slice($impact->matchedPaths, 0, 5) as $path)
                                            <li class="truncate" title="{{ $path }}">{{ $path }}</li>
                                        @endforeach
                                    </ul>
                                    @if (count($impact->matchedPaths) > 5)
                                        <span class="mt-2 block text-xs text-secondary">{{ __(':count more matched paths', ['count' => count($impact->matchedPaths) - 5]) }}</span>
                                    @endif
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>
