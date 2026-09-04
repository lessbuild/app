<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to account')"
        :route="route('account.index')"
    />

    <x-layouts.partials.heading
        :title="__('Sign-in history')"
        :description="__('Review successful password and social sign-ins retained for account security.')"
    />

    <form method="GET" action="{{ route('account.sign-ins.index') }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="method" class="block text-xs font-semibold uppercase text-secondary">{{ __('Method') }}</label>
                <select id="method" name="method" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All methods') }}</option>
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}" @selected($filters['method'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('Signed in from') }}</label>
                <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-1 w-full rounded">
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Signed in through') }}</label>
                <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-1 w-full rounded">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            <a href="{{ route('account.sign-ins.export', array_filter($filters, fn ($value) => $value !== null)) }}" class="button primary">
                {{ __('Export CSV') }}
            </a>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('account.sign-ins.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-secondary">
            {{ trans_choice(':count matching sign-in|:count matching sign-ins', $signIns->total(), ['count' => $signIns->total()]) }}
        </p>
        <p class="text-xs text-secondary">{{ __('Only successful sign-ins are recorded. Raw browser user agents are never displayed or exported.') }}</p>
    </div>

    @if ($signIns->isEmpty())
        <div class="mt-4 rounded-lg border border-primary bg-primary p-5 text-sm text-secondary">
            {{ array_filter($filters, fn ($value) => $value !== null) ? __('No sign-ins match these filters.') : __('No sign-in history yet.') }}
        </div>
    @else
        <div class="mt-4 overflow-x-auto rounded-lg border border-primary">
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
