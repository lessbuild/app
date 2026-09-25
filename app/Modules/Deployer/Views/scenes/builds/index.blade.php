<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-signal.ui.page-header
        eyebrow="{{ __('Release operations') }}"
        icon="cloud-upload"
        :title="__('Deployment history')"
        :description="__('Review filtered deployment outcomes, activity, and retained release details.')"
    >
    </x-signal.ui.page-header>

    @php($activeFilterCount = count(array_filter($filters, fn ($value) => $value !== null && $value !== '')))

    <x-signal.ui.filter-panel
        id="deployment-filters"
        class="mt-6"
        :open="$activeFilterCount > 0"
        :summary="$activeFilterCount > 0 ? __(':count active', ['count' => $activeFilterCount]) : null"
        :label="__('Filter deployments')"
    >
    <form method="GET" action="{{ route('builds.index') }}" class="space-y-4">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="ui-label">{{ __('Search') }}</label>
                <x-signal.ui.input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Repository, revision, commit, or note') }}"
                    class="ui-input" :restore="false" />
            </div>
            <div>
                <label for="repository_id" class="ui-label">{{ __('Repository') }}</label>
                <x-signal.ui.select id="repository_id" name="repository_id" class="ui-input">
                    <option value="">{{ __('All repositories') }}</option>
                    @foreach ($repositories as $repository)
                        <option value="{{ $repository->id }}" @selected((int) $filters['repository_id'] === $repository->id)>
                            {{ $repository->name }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="website_id" class="ui-label">{{ __('Website') }}</label>
                <x-signal.ui.select id="website_id" name="website_id" class="ui-input">
                    <option value="">{{ __('All websites') }}</option>
                    @foreach ($websites as $website)
                        <option value="{{ $website->id }}" @selected((int) $filters['website_id'] === $website->id)>
                            {{ $website->name }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="server_id" class="ui-label">{{ __('Server') }}</label>
                <x-signal.ui.select id="server_id" name="server_id" class="ui-input">
                    <option value="">{{ __('All servers') }}</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected((int) $filters['server_id'] === $server->id)>
                            {{ $server->label }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="provider_id" class="ui-label">{{ __('Source provider') }}</label>
                <x-signal.ui.select id="provider_id" name="provider_id" class="ui-input">
                    <option value="">{{ __('All source providers') }}</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->id }}" @selected((int) $filters['provider_id'] === $provider->id)>
                            {{ $provider->name }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="status" class="ui-label">{{ __('Status') }}</label>
                <x-signal.ui.select id="status" name="status" class="ui-input">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="trigger" class="ui-label">{{ __('Trigger') }}</label>
                <x-signal.ui.select id="trigger" name="trigger" class="ui-input">
                    <option value="">{{ __('All triggers') }}</option>
                    @foreach ($triggers as $trigger)
                        <option value="{{ $trigger }}" @selected($filters['trigger'] === $trigger)>
                            {{ str($trigger)->title() }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div class="flex items-end">
                <label class="ui-choice min-h-11 w-full items-center">
                    <x-signal.ui.input type="checkbox" name="latest" value="1" @checked($filters['latest']) class="ui-check" :restore="false" />
                    {{ __('Latest per repository only') }}
                </label>
            </div>
            <div class="flex items-end">
                <label class="ui-choice min-h-11 w-full items-center">
                    <x-signal.ui.input type="checkbox" name="active" value="1" @checked($filters['active']) class="ui-check" :restore="false" />
                    {{ __('Active deployments only') }}
                </label>
            </div>
            <div>
                <label for="date_from" class="ui-label">{{ __('Created from') }}</label>
                <x-signal.ui.input
                    id="date_from"
                    name="date_from"
                    type="date"
                    value="{{ $filters['date_from'] }}"
                    class="ui-input" :restore="false" />
            </div>
            <div>
                <label for="date_to" class="ui-label">{{ __('Created through') }}</label>
                <x-signal.ui.input
                    id="date_to"
                    name="date_to"
                    type="date"
                    value="{{ $filters['date_to'] }}"
                    class="ui-input" :restore="false" />
            </div>
        </div>
        <div class="flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('builds.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                {{ __('Export CSV') }}
            </x-signal.ui.button>
            @if ($activeFilterCount > 0)
                <x-signal.ui.button :href="route('builds.index')" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
            @endif
        </div>
    </form>
    </x-signal.ui.filter-panel>

    <x-signal.ui.insights
        id="builds-insights"
        class="mt-6"
        :summary="trans_choice(':count matching deployment|:count matching deployments', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-signal.ui.stat :label="__('Matching deployments')" :value="$metrics['total']" :description="__('Deployments in this filtered view.')" />
            <x-signal.ui.stat :label="__('Active deployments')" :value="$metrics['active']" :description="__('Queued, deploying, running, or timing out.')" />
            <x-signal.ui.stat :label="__('Succeeded')" :value="$metrics['succeeded']" :description="__('Matching successful deployments.')" />
            <x-signal.ui.stat :label="__('Failed')" :value="$metrics['failed']" :description="__('Matching failed deployments.')" />
            <x-signal.ui.stat
                :label="__('Observed success')"
                :value="$metrics['success_rate'] !== null ? $metrics['success_rate'].'%' : __('Not available')"
                :description="$metrics['success_rate'] !== null ? __('Succeeded versus failed outcomes; active and canceled runs excluded.') : __('No matching success or failure outcome.')"
            />
            <x-signal.ui.stat
                :label="__('Latest matching deployment')"
                :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')"
                :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching deployment recorded.')"
            />
        </dl>
    </x-signal.ui.insights>

    <!--
     ! ------------------------------------------------------------
    ! List Builds
     ! ------------------------------------------------------------
    !-->
    @if(!$builds->isEmpty())
        <x-signal.ui.panel id="deployment-history" class="ui-panel ui-inventory-list mt-6 divide-y divide-line overflow-hidden" aria-label="{{ __('Deployment history') }}">
            @foreach($builds as $build)
                <x-signal.ui.card as="a" tone="interactive" class="block p-4 sm:p-5" data-build-card href="{{ route('builds.show', $build) }}" aria-label="{{ __('View build #:id', ['id' => $build->id]) }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-signal.ui.avatar :name="$build->repository->name" class="ui-avatar-md shrink-0" />
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-ink">{{ $build->repository->name }}</p>
                                <p class="mt-0.5 truncate text-xs text-muted">{{ $build->repository->website->server->label }}</p>
                            </div>
                        </div>
                        <x-signal.ui.badge :tone="match ($build->status) {
                            \App\Modules\Deployer\Models\Build::STATUS_SUCCEEDED => 'success',
                            \App\Modules\Deployer\Models\Build::STATUS_FAILED => 'danger',
                            \App\Modules\Deployer\Models\Build::STATUS_CANCELED, \App\Modules\Deployer\Models\Build::STATUS_REJECTED => 'warning',
                            \App\Modules\Deployer\Models\Build::STATUS_RUNNING, \App\Modules\Deployer\Models\Build::STATUS_QUEUED, \App\Modules\Deployer\Models\Build::STATUS_TIMING_OUT => 'accent',
                            default => 'neutral',
                        }">
                            {{ str($build->status)->replace('_', ' ') }}
                        </x-signal.ui.badge>
                    </div>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Trigger') }}</dt>
                            <dd class="mt-1 text-ink">{{ ucfirst($build->trigger_source) }}</dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Revision') }}</dt>
                            <dd class="mt-1 font-mono text-xs text-ink">{{ $build->revision ? $build->shortRevision() : __('Current branch') }}</dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Finished') }}</dt>
                            <dd class="mt-1 text-ink">{{ $build->finished_at?->diffForHumans() ?? __('Not finished') }}</dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Duration') }}</dt>
                            <dd class="mt-1 text-ink">{{ __('Duration: :duration', ['duration' => $build->durationLabel() ?? __('Not recorded')]) }}</dd>
                        </div>
                    </dl>
                    @if ($build->operator_note)
                        <p class="mt-3 line-clamp-2 text-xs text-muted" title="{{ $build->operator_note }}">
                            {{ __('Note: :note', ['note' => str($build->operator_note)->limit(120)]) }}
                        </p>
                    @endif
                </x-signal.ui.card>
            @endforeach
        </x-signal.ui.panel>
        <div class="py-4">
            {{ $builds->links() }}
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No builds match these filters') : __('You have no builds')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('You have no recent builds')"
            >
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-slot:button>
                    <x-signal.ui.button :href="route('builds.index')" variant="primary">{{ __('Clear filters') }}</x-signal.ui.button>
                    </x-slot:button>
                @endif
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
