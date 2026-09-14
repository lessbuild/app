<x-layouts.app>
    <x-layouts.partials.heading
        icon="chip"
        :title="__('Cost visibility')"
        :description="__('Provider-catalog estimates, measured utilization signals, and budget awareness. Your provider invoice remains authoritative.')"
    />

    @unless($featureAvailable)
        <div class="mt-6 rounded-xl border border-ternary bg-primary p-4 text-sm text-secondary">
            <strong class="text-primary">{{ __('Pro feature') }}</strong>
            · {{ __('Upgrade to save workspace budgets. Read-only estimates remain available.') }}
            <a href="{{ route('pricing') }}" class="font-bold text-ternary underline">{{ __('Compare plans') }}</a>
        </div>
    @endunless

    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            [__('Estimated monthly'), '$'.number_format($estimated, 2)],
            [__('Configured servers'), $rows->count()],
            [__('Needs attention'), $idleCount],
            [__('Unknown prices'), $unknownCount],
        ] as [$label, $value])
            <div class="rounded-2xl border border-primary bg-primary p-5">
                <p class="text-xs font-bold uppercase text-secondary">{{ $label }}</p>
                <p class="mt-2 text-3xl font-black text-primary">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_22rem]">
        <section class="overflow-hidden rounded-2xl border border-primary bg-primary">
            <div class="border-b border-primary p-5">
                <h2 class="text-xl font-black text-primary">{{ __('Resource estimates') }}</h2>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('Monthly amounts are provider-catalog estimates, not provider billing. They exclude taxes, bandwidth overages, storage, discounts, and resources created outside BuildPusher.') }}
                </p>
            </div>

            <div class="divide-y divide-primary">
                @forelse($rows as $row)
                    <article class="grid gap-3 p-5 sm:grid-cols-[1fr_auto_auto] sm:items-center">
                        <div>
                            <a class="font-bold text-primary hover:underline" href="{{ route('servers.show', $row->server) }}">
                                {{ $row->server->label }}
                            </a>
                            <p class="text-xs text-secondary">
                                {{ $row->server->provider?->name }}
                                · {{ $row->server->size ?: __('Unknown size') }}
                                · {{ trans_choice(':count website|:count websites', $row->server->websites_count, ['count' => $row->server->websites_count]) }}
                            </p>
                        </div>

                        <div class="text-sm text-secondary">
                            <span class="block">
                                {{ $row->averageCpu === null ? __('No CPU sample') : __(':value% average CPU', ['value' => round($row->averageCpu)]) }}
                            </span>
                            <span class="text-xs">{{ __('Measured CPU telemetry') }}</span>
                        </div>

                        <div class="text-right">
                            <p class="font-black text-primary">
                                {{ $row->monthly === null ? '—' : '$'.number_format($row->monthly, 2).'/mo' }}
                            </p>
                            @if($row->monthly !== null)
                                @if($row->catalogObservedAt)
                                    <span class="block text-xs text-secondary">
                                        {{ __('Catalog observed :date', ['date' => $row->catalogObservedAt->toDayDateTimeString()]) }}
                                    </span>
                                @else
                                    <span class="block text-xs text-amber-700">{{ __('Catalog observation unavailable') }}</span>
                                @endif
                            @else
                                <span class="block text-xs text-amber-700">{{ __('Price source unavailable') }}</span>
                            @endif
                            @if($row->idle)
                                <span class="text-xs font-bold text-amber-700">{{ __('Review or hibernate') }}</span>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="p-6 text-sm text-secondary">{{ __('Provision or import a server to begin tracking estimates.') }}</div>
                @endforelse
            </div>
        </section>

        <aside class="space-y-5">
            <section class="rounded-2xl border border-primary bg-primary p-5">
                <h2 class="font-black text-primary">{{ __('Monthly budget') }}</h2>
                @if($budget)
                    <p class="mt-2 text-2xl font-black text-primary">{{ '$'.number_format($budget, 2) }}</p>
                    <p class="mt-1 text-sm {{ $estimated > $budget ? 'text-red-700' : 'text-secondary' }}">
                        {{ $estimated > $budget ? __('Estimate exceeds budget by $:amount.', ['amount' => number_format($estimated - $budget, 2)]) : __('$:amount estimated headroom.', ['amount' => number_format($budget - $estimated, 2)]) }}
                    </p>
                @else
                    <p class="mt-2 text-sm text-secondary">{{ __('Set a planning threshold to make cost changes visible before they become surprises.') }}</p>
                @endif

                @if($canManage)
                    <form method="POST" action="{{ route('costs.update') }}" class="mt-4">
                        @csrf
                        @method('PATCH')
                        <label>
                            <span class="block text-xs font-bold uppercase text-secondary">{{ __('Budget in USD') }}</span>
                            <input type="number" min="1" max="1000000" step="0.01" name="monthly_infrastructure_budget" value="{{ $budget }}" class="input secondary mt-1 rounded-sm">
                        </label>
                        <button type="submit" class="button primary mt-3 w-full">{{ __('Save budget') }}</button>
                    </form>
                @endif
            </section>

            <section class="rounded-2xl border border-primary bg-tertiary p-5 text-white">
                <h2 class="font-black">{{ __('Cost basis') }}</h2>
                <ul class="mt-3 space-y-2 text-sm text-tertiary">
                    <li>• {{ __('Monthly amount: stored provider-catalog estimate.') }}</li>
                    <li>• {{ __('CPU: measured BuildPusher telemetry, not billing usage.') }}</li>
                    <li>• {{ __('Provider billing: not connected; invoice remains authoritative.') }}</li>
                </ul>
            </section>

            <section class="rounded-2xl border border-primary bg-primary p-5">
                <h2 class="font-black text-primary">{{ __('Preview lifetime') }}</h2>
                <p class="mt-2 text-sm text-secondary">
                    @if($previewUsage->limit === null)
                        {{ trans_choice(':count active preview environment; no configured plan quota.|:count active preview environments; no configured plan quota.', $previewUsage->used, ['count' => $previewUsage->used]) }}
                    @else
                        {{ __(':used of :limit preview environments in use; quota is not a monetary limit.', ['used' => $previewUsage->used, 'limit' => $previewUsage->limit]) }}
                    @endif
                </p>
                @if($previewUsage->previews->isEmpty())
                    <p class="mt-3 text-sm text-secondary">{{ __('No active previews are using workspace capacity.') }}</p>
                @else
                    <ul class="mt-3 space-y-3 text-sm">
                        @foreach($previewUsage->previews as $lifetime)
                            <li class="border-t border-primary pt-3 first:border-0 first:pt-0">
                                <p class="font-bold text-primary">{{ $lifetime->project->name }} · PR #{{ $lifetime->preview->pull_request_number }}</p>
                                <p class="mt-1 text-secondary">{{ $lifetime->preview->status }} · {{ __(':count-hour configured lifetime', ['count' => $lifetime->ttlHours]) }}</p>
                                @if($lifetime->expired)
                                    <p class="mt-1 font-bold text-amber-700">{{ __('Past configured lifetime; cleanup is pending.') }}</p>
                                @else
                                    <p class="mt-1 text-secondary">{{ __('Expires :date', ['date' => $lifetime->expiresAt->toDayDateTimeString()]) }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($previewUsage->hiddenCount > 0)
                    <p class="mt-3 text-xs text-secondary">{{ __('Showing the first :count active previews; quota usage includes all active previews.', ['count' => $previewUsage->previews->count()]) }}</p>
                @endif
            </section>

            <section class="rounded-2xl border border-primary bg-primary p-5">
                <h2 class="font-black text-primary">{{ __('Optimization signals') }}</h2>
                <ul class="mt-3 space-y-2 text-sm text-secondary">
                    <li>• {{ __('Servers without websites are flagged.') }}</li>
                    <li>• {{ __('Sustained CPU below 10% is flagged for review.') }}</li>
                    <li>• {{ __('Use Automation to hibernate eligible environments.') }}</li>
                </ul>
            </section>
        </aside>
    </div>
</x-layouts.app>
