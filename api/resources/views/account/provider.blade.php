<x-signal.layouts.account :account="$account" :title="$provider->name" :description="$provider->type->label().' · '.$provider->type->purpose()">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @if (session('error'))
        <x-signal.ui.alert tone="danger" role="alert">{{ session('error') }}</x-signal.ui.alert>
    @endif
    @error('provider')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror

    <x-signal.ui.card class="flex flex-wrap items-center justify-between gap-4 p-5">
        <div class="flex flex-wrap items-center gap-3">
            @include('account._provider-health', ['provider' => $provider])
            <span class="text-sm text-muted">{{ $provider->connection_checked_at ? __('Checked :time', ['time' => $provider->connection_checked_at->diffForHumans()]) : __('Never checked') }}</span>
        </div>
        <form method="POST" action="{{ route('account.providers.check', $provider->id) }}">
            @csrf
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Check connection') }}</x-signal.ui.button>
        </form>
    </x-signal.ui.card>

    <x-signal.ui.settings-section :title="__('Settings')" :description="__('A new token, or a new type, resets the connection status until the next check.')">
        <form method="POST" action="{{ route('account.providers.update', $provider->id) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
            @csrf
            @method('PUT')
            @include('account._provider-fields', ['provider' => $provider])
            <div class="sm:col-span-2"><x-signal.ui.checkbox name="connection_monitoring_enabled" value="1" unchecked-value="0" :checked="$provider->connection_monitoring_enabled">{{ __('Check the connection automatically') }}</x-signal.ui.checkbox></div>
            <x-signal.ui.select-field name="connection_check_interval_minutes" :label="__('Check every')">
                @foreach ($intervals as $minutes)
                    <option value="{{ $minutes }}" @selected((int) old('connection_check_interval_minutes', $provider->connection_check_interval_minutes) === $minutes)>{{ $minutes < 60 * 24 ? trans_choice(':count hour|:count hours', intdiv($minutes, 60), ['count' => intdiv($minutes, 60)]) : __('Day') }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="connection_failure_threshold" :label="__('Mark failed after')">
                @foreach ($thresholds as $count)
                    <option value="{{ $count }}" @selected((int) old('connection_failure_threshold', $provider->connection_failure_threshold) === $count)>{{ trans_choice(':count failed check|:count failed checks in a row', $count, ['count' => $count]) }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save provider') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>

    <x-signal.ui.table :caption="__('Recent checks')">
        <x-slot:head><tr><th scope="col">{{ __('When (UTC)') }}</th><th scope="col">{{ __('Result') }}</th><th scope="col">{{ __('How') }}</th><th scope="col">{{ __('Time taken') }}</th></tr></x-slot:head>
        @forelse ($checks as $check)
            <tr>
                <td class="whitespace-nowrap">{{ $check->checked_at->format('Y-m-d H:i:s') }}</td>
                <td>{{ $check->successful ? __('Connected') : ($check->error ?? __('Failed')) }}</td>
                <td>{{ $check->source === 'automatic' ? __('Automatic') : __('Manual') }}</td>
                <td class="whitespace-nowrap">{{ number_format($check->duration_ms) }} ms</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-8 text-center text-muted">{{ __('No checks yet.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>

    <x-signal.ui.settings-section :title="__('Remove this provider')" :description="$servers->isEmpty() ? __('The token stops being used at once.') : __('Delete its servers first: :servers.', ['servers' => $servers->map->label()->implode(', ')])">
        <div class="p-4 sm:p-6">
            <x-signal.ui.button variant="danger" data-modal-trigger="delete-provider" :disabled="$servers->isNotEmpty()">{{ __('Remove provider') }}</x-signal.ui.button>
            <x-signal.overlays.delete-confirmation id="delete-provider" :route="route('account.providers.destroy', $provider->id)" :title="__('Remove :provider?', ['provider' => $provider->name])" :description="__('The token stops being used at once.')" :submit-label="__('Remove provider')" />
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.account>
