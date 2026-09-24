@props([
    'environment',
    'open' => false,
])

@php
    $windowDays = old('deployment_window_days', $environment->deployment_window_days ?? []);
    $windowDays = is_array($windowDays) ? $windowDays : [];
@endphp

<x-signal.overlays.modal
    id="environment-deployment-controls-dialog-{{ $environment->id }}"
    :title="__('Deployment controls')"
    :description="__('Set deployment locks, maintenance windows and rollout safeguards for this environment.')"
    :open="$open"
>
    <form method="POST" action="{{ route('environments.deployment-controls.update', ['environment' => $environment, 'dialog' => 'edit-deployment-controls-'.$environment->id]) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <x-signal.ui.input type="hidden" name="_environment_id" value="{{ $environment->id }}" :restore="false" />
        <x-signal.ui.input type="hidden" name="_environment_panel" value="deployment-controls" :restore="false" />
        <x-signal.ui.checkbox
            :id="'environment-deployment-controls-'.$environment->id.'-deployment-locked'"
            name="deployment_locked"
            :checked="(bool) old('deployment_locked', (bool) $environment->deployment_locked_at)"
            unchecked-value="0"
            :description="__('Manual, API, scheduled, and webhook deployments will wait.')"
        >{{ __('Lock deployments') }}</x-signal.ui.checkbox>
        <x-signal.ui.input-field
            :id="'environment-deployment-controls-'.$environment->id.'-deployment-lock-reason'"
            name="deployment_lock_reason"
            :label="__('Lock reason')"
            :value="$environment->deployment_lock_reason"
            maxlength="500"
            :placeholder="__('Reason for the lock (optional)')"
        />
        <div class="border-t border-line pt-4">
            <x-signal.ui.checkbox
                :id="'environment-deployment-controls-'.$environment->id.'-window-enabled'"
                name="deployment_window_enabled"
                :checked="(bool) old('deployment_window_enabled', ! empty($environment->deployment_window_days))"
                unchecked-value="0"
                :description="__('Allow new deployments only within this weekly window.')"
            >{{ __('Restrict deployment times') }}</x-signal.ui.checkbox>
        </div>
        <fieldset @if ($errors->has('deployment_window_days')) aria-describedby="environment-deployment-controls-{{ $environment->id }}-window-days-error" @endif>
            <legend id="environment-deployment-controls-{{ $environment->id }}-window-days-label" class="ui-label">{{ __('Window days') }}</legend>
            <div class="mt-2 flex flex-wrap gap-3">
                @foreach ([1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')] as $day => $label)
                    <x-signal.ui.checkbox
                        :id="'environment-deployment-controls-'.$environment->id.'-window-day-'.$day"
                        name="deployment_window_days[]"
                        :value="$day"
                        :checked="in_array($day, $windowDays)"
                        :restore="false"
                        :error-key="false"
                        :show-errors="false"
                        container-class="inline-flex"
                        label-class="gap-1.5 text-sm font-normal"
                        :aria-invalid="$errors->has('deployment_window_days') ? 'true' : 'false'"
                        :aria-describedby="$errors->has('deployment_window_days') ? 'environment-deployment-controls-'.$environment->id.'-window-days-error' : null"
                    >{{ $label }}</x-signal.ui.checkbox>
                @endforeach
            </div>
            <x-forms.errors name="deployment_window_days" :id="'environment-deployment-controls-'.$environment->id.'-window-days-error'" />
        </fieldset>
        <div class="grid gap-3 sm:grid-cols-2">
            <x-signal.ui.input-field
                :id="'environment-deployment-controls-'.$environment->id.'-window-start'"
                name="deployment_window_start"
                :label="__('Starts')"
                :value="$environment->deployment_window_start ? substr($environment->deployment_window_start, 0, 5) : '09:00'"
                type="time"
                class="mt-1"
            />
            <x-signal.ui.input-field
                :id="'environment-deployment-controls-'.$environment->id.'-window-end'"
                name="deployment_window_end"
                :label="__('Ends')"
                :value="$environment->deployment_window_end ? substr($environment->deployment_window_end, 0, 5) : '17:00'"
                type="time"
                class="mt-1"
            />
        </div>
        <x-signal.ui.input-field
            :id="'environment-deployment-controls-'.$environment->id.'-window-timezone'"
            name="deployment_window_timezone"
            :label="__('Timezone')"
            :value="$environment->deployment_window_timezone ?: 'UTC'"
            list="deployment-timezones-{{ $environment->id }}"
            placeholder="Europe/London"
        >
            <datalist id="deployment-timezones-{{ $environment->id }}"><option value="UTC"><option value="Europe/London"><option value="Europe/Berlin"><option value="America/New_York"><option value="America/Chicago"><option value="America/Denver"><option value="America/Los_Angeles"><option value="Asia/Singapore"><option value="Asia/Tokyo"></datalist>
        </x-signal.ui.input-field>
        <div class="border-t border-line pt-4">
            <x-signal.ui.select-field
                :id="'environment-deployment-controls-'.$environment->id.'-strategy'"
                name="deployment_strategy"
                :label="__('Release strategy')"
                :description="__('Blue/green atomically switches release directories. Canary sends loopback HTTP requests to the candidate before the switch. Rolling keeps replicated workers available while restarting them one at a time; web traffic still switches atomically.')"
                class="mt-1"
            >
                @foreach (\App\Modules\Deployer\Models\Environment::DEPLOYMENT_STRATEGIES as $strategy)
                    <option value="{{ $strategy }}" @selected(old('deployment_strategy', $environment->deployment_strategy ?: 'blue_green') === $strategy)>{{ str($strategy)->replace('_', ' ')->title() }}</option>
                @endforeach
            </x-signal.ui.select-field>
        </div>
        <x-signal.ui.select-field
            :id="'environment-deployment-controls-'.$environment->id.'-rolling-pause-seconds'"
            name="rolling_pause_seconds"
            :label="__('Pause between rolling workers')"
            class="mt-1"
        >
            @foreach ([0, 1, 2, 5, 10, 30] as $seconds)
                <option value="{{ $seconds }}" @selected((int) old('rolling_pause_seconds', $environment->rolling_pause_seconds ?? 2) === $seconds)>{{ trans_choice(':count second|:count seconds', $seconds, ['count' => $seconds]) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.checkbox
            :id="'environment-deployment-controls-'.$environment->id.'-automatic-rollback'"
            name="automatic_rollback"
            :checked="(bool) old('automatic_rollback', $environment->automatic_rollback)"
            unchecked-value="0"
            :description="__('If an activated release fails, immediately switch back to the most recent retained successful release.')"
        >{{ __('Automatic rollback') }}</x-signal.ui.checkbox>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save deployment controls') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
