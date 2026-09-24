@props([
    'servers',
    'website' => null,
    'fieldPrefix' => '',
])

<div class="space-y-6 bg-surface p-5 sm:p-6">
    <x-signal.ui.select-field
        :id="$fieldPrefix.'server_id'"
        name="server_id"
        :label="__('Server')"
        required
        class="w-full"
    >
        @foreach ($servers as $server)
            <option value="{{ $server->id }}" @selected((string) old('server_id', $website?->server_id ?? '') === (string) $server->id)>
                {{ $server->label }} ({{ str($server->type->value)->replace('-', ' ')->title() }})
            </option>
        @endforeach
    </x-signal.ui.select-field>

    <x-signal.ui.input-field
        :id="$fieldPrefix.'name'"
        name="name"
        :label="__('Website Name')"
        :value="$website?->name"
        placeholder="Example: Deployer"
        class="w-full"
    />

    <x-signal.ui.input-field
        :id="$fieldPrefix.'url'"
        name="url"
        :label="__('Website URL')"
        :value="$website?->url"
        placeholder="www.example.com"
    >
        <x-slot:prefix>
            <x-signal.ui.input-addon>http://</x-signal.ui.input-addon>
        </x-slot:prefix>
    </x-signal.ui.input-field>

    <x-signal.ui.textarea-field
        :id="$fieldPrefix.'environment'"
        name="environment"
        :label="__('Environment')"
        :value="$website?->environment"
        rows="3"
        class="w-full"
        placeholder="APP_ENV=production...."
        :description="__('Your environment file contents')"
    />

    <x-signal.ui.input-field
        :id="$fieldPrefix.'release_retention'"
        name="release_retention"
        :label="__('Retained releases')"
        :value="$website?->release_retention ?? 5"
        type="number"
        min="2"
        max="20"
        step="1"
        inputmode="numeric"
        class="w-full"
        :description="__('Keep between 2 and 20 releases on the server for rollback and recovery.')"
    />

    <x-signal.ui.card as="fieldset" class="bg-surface-muted p-5" :shadow="false">
        <legend class="ui-eyebrow">{{ __('Health checks') }}</legend>

        <x-signal.ui.checkbox
            :id="$fieldPrefix.'health_check_enabled'"
            name="health_check_enabled"
            :checked="(bool) old('health_check_enabled', $website?->health_check_enabled ?? false)"
            unchecked-value="0"
            :description="__('A failed check restores the previous release symlink. Database migrations are not rolled back.')"
        >{{ __('Verify website health after deployment') }}</x-signal.ui.checkbox>

        <div class="mt-4">
            <x-signal.ui.input-field
                :id="$fieldPrefix.'health_check_path'"
                name="health_check_path"
                :label="__('Health check path')"
                :value="$website?->health_check_path ?? '/'"
                placeholder="/health"
                :description="__('Redirects are followed; the final response must be successful.')"
            >
                <x-slot:prefix>
                    <x-signal.ui.input-addon tone="surface">http://{{ old('url', $website?->url ?? __('website')) }}</x-signal.ui.input-addon>
                </x-slot:prefix>
            </x-signal.ui.input-field>
        </div>

        <div class="mt-4 border-t border-line pt-4">
            <x-signal.ui.checkbox
                :id="$fieldPrefix.'health_monitoring_enabled'"
                name="health_monitoring_enabled"
                :checked="(bool) old('health_monitoring_enabled', $website?->health_monitoring_enabled ?? true)"
                unchecked-value="0"
                :description="__('Run scheduled checks and alert on outages. Deployment checks and manual checks remain available when scheduled monitoring is paused.')"
            >{{ __('Automatically monitor website health') }}</x-signal.ui.checkbox>
        </div>

        <div class="mt-4 border-t border-line pt-4">
            <x-signal.ui.select-field
                :id="$fieldPrefix.'health_check_interval_minutes'"
                name="health_check_interval_minutes"
                :label="__('Automatic check interval')"
                :description="__('Applies to scheduled monitoring only. Manual and post-deployment checks can still run immediately.')"
                class="w-full"
            >
                @foreach (\App\Modules\Deployer\Models\Website::HEALTH_CHECK_INTERVALS as $minutes)
                    <option value="{{ $minutes }}" @selected((int) old('health_check_interval_minutes', $website?->health_check_interval_minutes ?? \App\Modules\Deployer\Models\Website::DEFAULT_HEALTH_CHECK_INTERVAL_MINUTES) === $minutes)>
                        {{ trans_choice('Every :count minute|Every :count minutes', $minutes, ['count' => $minutes]) }}
                    </option>
                @endforeach
            </x-signal.ui.select-field>
        </div>

        <div class="mt-4 border-t border-line pt-4">
            <x-signal.ui.select-field
                :id="$fieldPrefix.'health_failure_threshold'"
                name="health_failure_threshold"
                :label="__('Outage confirmation')"
                :description="__('A successful check resets the count. An alert is created only when this threshold is first reached.')"
                class="w-full"
            >
                @foreach (\App\Modules\Deployer\Models\Website::HEALTH_FAILURE_THRESHOLDS as $failures)
                    <option value="{{ $failures }}" @selected((int) old('health_failure_threshold', $website?->health_failure_threshold ?? \App\Modules\Deployer\Models\Website::defaultHealthFailureThreshold()) === $failures)>
                        {{ trans_choice('After :count consecutive failure|After :count consecutive failures', $failures, ['count' => $failures]) }}
                    </option>
                @endforeach
            </x-signal.ui.select-field>
        </div>
    </x-signal.ui.card>

    <x-signal.ui.textarea-field
        :id="$fieldPrefix.'description'"
        name="description"
        :label="__('Description')"
        :value="$website?->description"
        rows="3"
        class="w-full"
        placeholder="My website"
        :description="__('Brief description of your website')"
    />
</div>
