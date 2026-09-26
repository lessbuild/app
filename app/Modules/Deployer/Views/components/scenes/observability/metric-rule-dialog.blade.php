@props([
    'servers',
    'open' => false,
])

<x-signal.overlays.modal
    id="metric-rule-dialog"
    :title="__('Create an alert rule')"
    :description="__('Trigger a notification after a sustained metric threshold breach.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.metric-rules.store') }}" class="space-y-4">
        @csrf
        <x-signal.ui.input type="hidden" name="_metric_rule_form" value="1" :restore="false" />
        <label class="block">
            <span class="ui-label">{{ __('Name') }}</span>
            <x-signal.ui.input name="name" value="{{ old('name') }}" required class="ui-input" placeholder="High memory" :restore="false" />
            <x-forms.errors name="name" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Server') }}</span>
            <x-signal.ui.select name="server_id" class="ui-input">
                <option value="">{{ __('All servers') }}</option>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}" @selected((string) old('server_id') === (string) $server->id)>{{ $server->label }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="server_id" />
        </label>
        <div class="grid grid-cols-2 gap-2">
            <label class="block">
                <span class="sr-only">{{ __('Metric') }}</span>
                <x-signal.ui.select name="metric" class="ui-input" aria-label="{{ __('Metric') }}">
                    @foreach (\App\Modules\Deployer\Models\MetricAlertRule::METRICS as $metric)
                        <option value="{{ $metric }}" @selected(old('metric', 'cpu_percent') === $metric)>{{ str($metric)->replace('_', ' ')->headline() }}</option>
                    @endforeach
                </x-signal.ui.select>
                <x-forms.errors name="metric" />
            </label>
            <label class="block">
                <span class="sr-only">{{ __('Operator') }}</span>
                <x-signal.ui.select name="operator" class="ui-input" aria-label="{{ __('Operator') }}">
                    <option value="gte" @selected(old('operator', 'gte') === 'gte')>≥</option>
                    <option value="lte" @selected(old('operator') === 'lte')>≤</option>
                </x-signal.ui.select>
                <x-forms.errors name="operator" />
            </label>
        </div>
        <div class="grid grid-cols-3 gap-2">
            <label class="block">
                <span class="sr-only">{{ __('Threshold') }}</span>
                <x-signal.ui.input type="number" step="0.01" min="0" name="threshold" value="{{ old('threshold', 85) }}" class="ui-input" aria-label="{{ __('Threshold') }}" :restore="false" />
                <x-forms.errors name="threshold" />
            </label>
            <label class="block">
                <span class="sr-only">{{ __('Consecutive breaches') }}</span>
                <x-signal.ui.input type="number" min="1" max="10" name="consecutive_breaches" value="{{ old('consecutive_breaches', 3) }}" class="ui-input" aria-label="{{ __('Consecutive breaches') }}" :restore="false" />
                <x-forms.errors name="consecutive_breaches" />
            </label>
            <label class="block">
                <span class="sr-only">{{ __('Cooldown') }}</span>
                <x-signal.ui.select name="cooldown_minutes" class="ui-input" aria-label="{{ __('Cooldown') }}">
                    @foreach ([5 => '5m', 15 => '15m', 30 => '30m', 60 => '1h', 180 => '3h', 1440 => '24h'] as $minutes => $label)
                        <option value="{{ $minutes }}" @selected((string) old('cooldown_minutes', 30) === (string) $minutes)>{{ $label }}</option>
                    @endforeach
                </x-signal.ui.select>
                <x-forms.errors name="cooldown_minutes" />
            </label>
        </div>
        <x-signal.ui.button type="submit" variant="primary" class="w-full">{{ __('Create alert') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
