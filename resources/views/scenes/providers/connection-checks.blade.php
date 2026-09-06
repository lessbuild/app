<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :provider', ['provider' => $provider->name])"
        :route="route('providers.show', $provider)"
    ></x-layouts.partials.breadcrumbs>

    <x-layouts.partials.heading
        :title="__('Connection check history')"
        :description="__('Review the retained credential-check evidence for :provider.', ['provider' => $provider->name])"
    ></x-layouts.partials.heading>

    <form method="GET" action="{{ route('providers.connection-checks.index', $provider) }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="result" class="block text-xs font-semibold uppercase text-secondary">{{ __('Result') }}</label>
                <select id="result" name="result" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All results') }}</option>
                    <option value="healthy" @selected($filters['result'] === 'healthy')>{{ __('Healthy') }}</option>
                    <option value="failed" @selected($filters['result'] === 'failed')>{{ __('Failed') }}</option>
                </select>
            </div>
            <div>
                <label for="source" class="block text-xs font-semibold uppercase text-secondary">{{ __('Source') }}</label>
                <select id="source" name="source" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All sources') }}</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source }}" @selected($filters['source'] === $source)>{{ str($source)->title() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('Checked from') }}</label>
                <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-1 w-full rounded-sm">
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Checked through') }}</label>
                <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-1 w-full rounded-sm">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            <a href="{{ route('providers.connection-checks.export', [$provider, ...array_filter($filters, fn ($value) => $value !== null)]) }}" class="button primary">
                {{ __('Export CSV') }}
            </a>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('providers.connection-checks.index', $provider) }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Matching checks') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['total'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Checks in this filtered retained sample.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Healthy checks') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['healthy'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching successful credential checks.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Failed checks') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['failed'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching unsuccessful credential checks.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Observed success') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">
                {{ $metrics['success_rate'] !== null ? $metrics['success_rate'].'%' : __('Not available') }}
            </dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Recorded rate, not current credential validity.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Median successful response') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">
                {{ $metrics['median_successful_duration_ms'] !== null ? $metrics['median_successful_duration_ms'].' ms' : __('Not recorded') }}
            </dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Median of matching successful checks.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Latest matching check') }}</dt>
            <dd class="mt-1 text-lg font-bold text-primary">
                {{ $metrics['latest_at']?->diffForHumans() ?? __('Not available') }}
            </dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Newest check in the filtered sample.') }}</dd>
        </div>
    </dl>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-secondary">
            {{ trans_choice(':count matching retained check|:count matching retained checks', $connectionChecks->total(), ['count' => $connectionChecks->total()]) }}
        </p>
        <p class="text-xs text-secondary">{{ __('History is limited to the newest :limit retained checks per provider.', ['limit' => \App\Models\ProviderConnectionCheck::MAX_PER_PROVIDER]) }}</p>
    </div>

    @if ($connectionChecks->isEmpty())
        <div class="mt-4 rounded-lg border border-primary bg-primary p-5 text-sm text-secondary">
            {{ array_filter($filters, fn ($value) => $value !== null) ? __('No connection checks match these filters.') : __('No connection checks have been recorded yet.') }}
        </div>
    @else
        <div class="mt-4 overflow-x-auto rounded-lg border border-primary">
            <table class="min-w-full divide-y divide-primary bg-primary text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Result') }}</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Source') }}</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Provider type') }}</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Response') }}</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Endpoint') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold text-secondary">{{ __('Checked') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary">
                    @foreach ($connectionChecks as $check)
                        <tr class="align-top">
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2 py-1 text-xs font-semibold uppercase',
                                    'bg-green-100 text-green-700' => $check->successful,
                                    'bg-red-100 text-red-700' => ! $check->successful,
                                ])>{{ $check->successful ? __('Healthy') : __('Failed') }}</span>
                                @if ($check->error)
                                    <p class="mt-2 max-w-md whitespace-pre-wrap break-words text-xs text-red-700">{{ $check->error }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-primary">{{ str($check->source)->title() }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-primary">{{ str($check->provider_type)->headline() }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-primary">
                                {{ $check->http_status ? __('HTTP :status', ['status' => $check->http_status]) : __('No status') }}
                                <span class="block text-xs text-secondary">{{ __(':duration ms', ['duration' => $check->duration_ms]) }}</span>
                            </td>
                            <td class="max-w-md break-all px-4 py-3 font-mono text-xs text-primary">{{ $check->endpoint ?? __('Unavailable') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-secondary" title="{{ $check->checked_at }}">
                                {{ $check->checked_at->diffForHumans() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="py-4">{{ $connectionChecks->links() }}</div>
    @endif
</x-layouts.app>
