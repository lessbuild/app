@props([
    'environment',
    'open' => false,
])

@php
    $windowDays = old('deployment_window_days', $environment->deployment_window_days ?? []);
    $windowDays = is_array($windowDays) ? $windowDays : [];
@endphp

<x-dialogs.modal
    id="environment-deployment-controls-dialog-{{ $environment->id }}"
    :title="__('Deployment controls')"
    :description="__('Set deployment locks, maintenance windows and rollout safeguards for this environment.')"
    :open="$open"
>
    <form method="POST" action="{{ route('environments.deployment-controls.update', ['environment' => $environment, 'dialog' => 'edit-deployment-controls-'.$environment->id]) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <input type="hidden" name="_environment_id" value="{{ $environment->id }}">
        <input type="hidden" name="_environment_panel" value="deployment-controls">
        <label class="flex items-start gap-3">
            <input type="hidden" name="deployment_locked" value="0">
            <input class="ui-check mt-1" type="checkbox" name="deployment_locked" value="1" @checked((bool) old('deployment_locked', (bool) $environment->deployment_locked_at))>
            <span><span class="block text-sm font-bold text-ink">{{ __('Lock deployments') }}</span><span class="text-xs text-muted">{{ __('Manual, API, scheduled, and webhook deployments will wait.') }}</span></span>
        </label>
        <label>
            <span class="ui-label">{{ __('Lock reason') }}</span>
            <input name="deployment_lock_reason" maxlength="500" value="{{ old('deployment_lock_reason', $environment->deployment_lock_reason) }}" class="ui-input" placeholder="{{ __('Reason for the lock (optional)') }}">
            <x-forms.errors name="deployment_lock_reason" />
        </label>
        <div class="border-t border-line pt-4">
            <label class="flex items-start gap-3">
                <input type="hidden" name="deployment_window_enabled" value="0">
                <input class="ui-check mt-1" type="checkbox" name="deployment_window_enabled" value="1" @checked((bool) old('deployment_window_enabled', ! empty($environment->deployment_window_days)))>
                <span><span class="block text-sm font-bold text-ink">{{ __('Restrict deployment times') }}</span><span class="text-xs text-muted">{{ __('Allow new deployments only within this weekly window.') }}</span></span>
            </label>
        </div>
        <fieldset>
            <legend class="ui-label">{{ __('Window days') }}</legend>
            <div class="mt-2 flex flex-wrap gap-3">
                @foreach ([1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')] as $day => $label)
                    <label class="flex items-center gap-1.5 text-sm text-ink"><input class="ui-check" type="checkbox" name="deployment_window_days[]" value="{{ $day }}" @checked(in_array($day, $windowDays))>{{ $label }}</label>
                @endforeach
            </div>
            <x-forms.errors name="deployment_window_days" />
        </fieldset>
        <div class="grid gap-3 sm:grid-cols-2">
            <label>
                <span class="ui-label">{{ __('Starts') }}</span>
                <input type="time" name="deployment_window_start" value="{{ old('deployment_window_start', $environment->deployment_window_start ? substr($environment->deployment_window_start, 0, 5) : '09:00') }}" class="ui-input mt-1">
                <x-forms.errors name="deployment_window_start" />
            </label>
            <label>
                <span class="ui-label">{{ __('Ends') }}</span>
                <input type="time" name="deployment_window_end" value="{{ old('deployment_window_end', $environment->deployment_window_end ? substr($environment->deployment_window_end, 0, 5) : '17:00') }}" class="ui-input mt-1">
                <x-forms.errors name="deployment_window_end" />
            </label>
        </div>
        <label>
            <span class="ui-label">{{ __('Timezone') }}</span>
            <input name="deployment_window_timezone" list="deployment-timezones-{{ $environment->id }}" value="{{ old('deployment_window_timezone', $environment->deployment_window_timezone ?: 'UTC') }}" class="ui-input" placeholder="Europe/London">
            <datalist id="deployment-timezones-{{ $environment->id }}"><option value="UTC"><option value="Europe/London"><option value="Europe/Berlin"><option value="America/New_York"><option value="America/Chicago"><option value="America/Denver"><option value="America/Los_Angeles"><option value="Asia/Singapore"><option value="Asia/Tokyo"></datalist>
            <x-forms.errors name="deployment_window_timezone" />
        </label>
        <div class="border-t border-line pt-4">
            <label>
                <span class="ui-label">{{ __('Release strategy') }}</span>
                <select name="deployment_strategy" class="ui-input mt-1">
                    @foreach (\App\Models\Environment::DEPLOYMENT_STRATEGIES as $strategy)
                        <option value="{{ $strategy }}" @selected(old('deployment_strategy', $environment->deployment_strategy ?: 'blue_green') === $strategy)>{{ str($strategy)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                <x-forms.errors name="deployment_strategy" />
            </label>
            <p class="ui-help">{{ __('Blue/green atomically switches release directories. Canary sends loopback HTTP requests to the candidate before the switch. Rolling keeps replicated workers available while restarting them one at a time; web traffic still switches atomically.') }}</p>
        </div>
        <label>
            <span class="ui-label">{{ __('Pause between rolling workers') }}</span>
            <select name="rolling_pause_seconds" class="ui-input mt-1">
                @foreach ([0, 1, 2, 5, 10, 30] as $seconds)
                    <option value="{{ $seconds }}" @selected((int) old('rolling_pause_seconds', $environment->rolling_pause_seconds ?? 2) === $seconds)>{{ trans_choice(':count second|:count seconds', $seconds, ['count' => $seconds]) }}</option>
                @endforeach
            </select>
            <x-forms.errors name="rolling_pause_seconds" />
        </label>
        <label class="ui-choice">
            <input type="hidden" name="automatic_rollback" value="0">
            <input class="ui-check mt-1" type="checkbox" name="automatic_rollback" value="1" @checked((bool) old('automatic_rollback', $environment->automatic_rollback))>
            <span><span class="block text-sm font-bold text-ink">{{ __('Automatic rollback') }}</span><span class="text-xs leading-5 text-muted">{{ __('If an activated release fails, immediately switch back to the most recent retained successful release.') }}</span></span>
        </label>
        <x-forms.errors name="automatic_rollback" />
        <x-ui.button type="submit" variant="primary">{{ __('Save deployment controls') }}</x-ui.button>
    </form>
</x-dialogs.modal>
