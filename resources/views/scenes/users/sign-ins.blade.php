<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to account')"
        :route="route('account.index')"
    />

    <x-layouts.partials.heading
        :title="__('Sign-in history')"
        :description="__('Review successful password and social sign-ins retained for account security.')"
    />

    <x-ui.card class="mt-8 p-4 sm:p-5">
        <form method="GET" action="{{ route('account.sign-ins.index') }}">
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="method" class="block text-xs font-semibold uppercase text-secondary">{{ __('Method') }}</label>
                <select id="method" name="method" class="input secondary mt-1 w-full rounded-lg">
                    <option value="">{{ __('All methods') }}</option>
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}" @selected($filters['method'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('Signed in from') }}</label>
                <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-1 w-full rounded-lg">
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Signed in through') }}</label>
                <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-1 w-full rounded-lg">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
            <x-ui.button href="{{ route('account.sign-ins.export', array_filter($filters, fn ($value) => $value !== null)) }}" variant="secondary">
                {{ __('Export CSV') }}
            </x-ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-ui.button href="{{ route('account.sign-ins.index') }}" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>
        </form>
    </x-ui.card>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-secondary">
            {{ trans_choice(':count matching sign-in|:count matching sign-ins', $signIns->total(), ['count' => $signIns->total()]) }}
        </p>
        <p class="text-xs text-secondary">{{ __('Only successful sign-ins are recorded. Raw browser user agents are never displayed or exported.') }}</p>
    </div>

    <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat class="ui-card" :label="__('Matching sign-ins')" :value="$metrics['total']" :description="__('Successful events in this filtered view.')" />
        <x-ui.stat class="ui-card" :label="__('Password sign-ins')" :value="$metrics['password']" :description="__('Authenticated with the local password.')" />
        <x-ui.stat class="ui-card" :label="__('Social sign-ins')" :value="$metrics['social']" :description="__('Recognized GitHub, GitLab, or Bitbucket events.')" />
        <x-ui.stat class="ui-card" :label="__('Known IP addresses')" :value="$metrics['known_ips']" :description="__('Distinct validated addresses in this view.')" />
        <x-ui.stat class="ui-card" :label="__('Latest matching sign-in')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching event recorded.')" />
    </dl>

    @if ($signIns->isEmpty())
        <x-ui.empty-state class="mt-4" :title="array_filter($filters, fn ($value) => $value !== null) ? __('No sign-ins match these filters.') : __('No sign-in history yet.')" />
    @else
        <div class="ui-card mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-primary bg-primary text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Browser and device') }}</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Method') }}</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('IP address') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold text-secondary">{{ __('Signed in') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary">
                    @foreach ($signIns as $signIn)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-medium text-primary">{{ $signIn['device'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-primary">{{ $signIn['method'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-primary">{{ $signIn['ip_address'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-secondary">
                                <time datetime="{{ $signIn['signed_in_at']->toIso8601String() }}" title="{{ $signIn['signed_in_at']->toDayDateTimeString() }}">
                                    {{ $signIn['signed_in_at']->diffForHumans() }}
                                </time>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="py-4">{{ $signIns->links() }}</div>
    @endif
</x-layouts.app>
