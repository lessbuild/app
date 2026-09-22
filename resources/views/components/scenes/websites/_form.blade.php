@props([
    'servers',
    'website' => null,
    'fieldPrefix' => '',
])

<div class="space-y-6 bg-surface p-5 sm:p-6">

    <div>
        <label for="{{ $fieldPrefix }}server_id" class="ui-label">
            {{ __('Server') }}
        </label>
        <div class="mt-2">
            <select id="{{ $fieldPrefix }}server_id" name="server_id" class="ui-input w-full" required>
                @foreach($servers as $server)
                    <option value="{{ $server->id }}"
                        @selected((string) old('server_id', $website?->server_id ?? '') === (string) $server->id)
                    >
                        {{ $server->label }} ({{ str($server->type->value)->replace('-', ' ')->title() }})
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="server_id"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}name" class="ui-label">
            {{ __('Website Name') }}
        </label>
        <div class="mt-2">
            <input
                value="{{ old('name') ?? ($website?->name ?? null) }}"
                type="text"
                name="name"
                id="{{ $fieldPrefix }}name"
                class="ui-input w-full"
                placeholder="Example: Deployer">
        </div>
        <x-forms.errors name="name"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}url" class="ui-label">
            {{ __('Website URL') }}
        </label>
        <div class="mt-2 flex">
            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-line bg-surface-muted px-3 text-sm text-muted">
                http://
            </span>
            <input
                value="{{ old('url') ?? ($website?->url ?? null) }}"
                type="text"
                name="url"
                id="{{ $fieldPrefix }}url"
                class="ui-input w-full rounded-l-none"
                placeholder="www.example.com">
        </div>
        <x-forms.errors name="url"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}environment" class="ui-label">
            {{ __('Environment') }}
        </label>
        <div class="mt-2">
            <textarea
                id="{{ $fieldPrefix }}environment"
                name="environment"
                rows="3"
                class="ui-input w-full"
                placeholder="APP_ENV=production....">{{ old('environment') ?? ($website?->environment ?? null) }}</textarea>
        </div>
        <p class="ui-help">
            {{ __('Your environment file contents') }}
        </p>
        <x-forms.errors name="environment"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}release_retention" class="ui-label">
            {{ __('Retained releases') }}
        </label>
        <div class="mt-2">
            <input
                value="{{ old('release_retention', $website?->release_retention ?? 5) }}"
                type="number"
                name="release_retention"
                id="{{ $fieldPrefix }}release_retention"
                min="2"
                max="20"
                step="1"
                inputmode="numeric"
                class="ui-input w-full"
            >
        </div>
        <p class="ui-help">
            {{ __('Keep between 2 and 20 releases on the server for rollback and recovery.') }}
        </p>
        <x-forms.errors name="release_retention"></x-forms.errors>
    </div>

    <fieldset class="ui-panel bg-surface-muted p-5">
        <legend class="ui-eyebrow">{{ __('Health checks') }}</legend>
        <div class="flex items-start gap-3">
            <input type="hidden" name="health_check_enabled" value="0">
            <input
                id="{{ $fieldPrefix }}health_check_enabled"
                name="health_check_enabled"
                type="checkbox"
                value="1"
                class="ui-check mt-1"
                @checked((bool) old('health_check_enabled', $website?->health_check_enabled ?? false))
            >
            <div>
                <label for="{{ $fieldPrefix }}health_check_enabled" class="font-semibold text-ink">
                    {{ __('Verify website health after deployment') }}
                </label>
                <p class="ui-help">
                    {{ __('A failed check restores the previous release symlink. Database migrations are not rolled back.') }}
                </p>
            </div>
        </div>

        <div class="mt-4">
            <label for="{{ $fieldPrefix }}health_check_path" class="ui-label">
                {{ __('Health check path') }}
            </label>
            <div class="mt-2 flex">
                <span class="inline-flex items-center rounded-l-lg border border-r-0 border-line bg-surface px-3 text-sm text-muted">
                    http://{{ old('url', $website?->url ?? __('website')) }}
                </span>
                <input
                    value="{{ old('health_check_path', $website?->health_check_path ?? '/') }}"
                    type="text"
                    name="health_check_path"
                    id="{{ $fieldPrefix }}health_check_path"
                    class="ui-input w-full rounded-l-none"
                    placeholder="/health"
                >
            </div>
            <p class="ui-help">
                {{ __('Redirects are followed; the final response must be successful.') }}
            </p>
            <x-forms.errors name="health_check_enabled"></x-forms.errors>
            <x-forms.errors name="health_check_path"></x-forms.errors>
        </div>

        <div class="mt-4 flex items-start gap-3 border-t border-line pt-4">
            <input type="hidden" name="health_monitoring_enabled" value="0">
            <input
                id="{{ $fieldPrefix }}health_monitoring_enabled"
                name="health_monitoring_enabled"
                type="checkbox"
                value="1"
                class="ui-check mt-1"
                @checked((bool) old('health_monitoring_enabled', $website?->health_monitoring_enabled ?? true))
            >
            <div>
                <label for="{{ $fieldPrefix }}health_monitoring_enabled" class="font-semibold text-ink">
                    {{ __('Automatically monitor website health') }}
                </label>
                <p class="ui-help">
                    {{ __('Run scheduled checks and alert on outages. Deployment checks and manual checks remain available when scheduled monitoring is paused.') }}
                </p>
            </div>
        </div>
        <x-forms.errors name="health_monitoring_enabled"></x-forms.errors>

        <div class="mt-4 border-t border-line pt-4">
            <label for="{{ $fieldPrefix }}health_check_interval_minutes" class="ui-label">
                {{ __('Automatic check interval') }}
            </label>
            <select
                id="{{ $fieldPrefix }}health_check_interval_minutes"
                name="health_check_interval_minutes"
                class="ui-input mt-2 w-full"
            >
                @foreach (\App\Models\Website::HEALTH_CHECK_INTERVALS as $minutes)
                    <option
                        value="{{ $minutes }}"
                        @selected((int) old('health_check_interval_minutes', $website?->health_check_interval_minutes ?? \App\Models\Website::DEFAULT_HEALTH_CHECK_INTERVAL_MINUTES) === $minutes)
                    >
                        {{ trans_choice('Every :count minute|Every :count minutes', $minutes, ['count' => $minutes]) }}
                    </option>
                @endforeach
            </select>
            <p class="ui-help">
                {{ __('Applies to scheduled monitoring only. Manual and post-deployment checks can still run immediately.') }}
            </p>
            <x-forms.errors name="health_check_interval_minutes"></x-forms.errors>
        </div>

        <div class="mt-4 border-t border-line pt-4">
            <label for="{{ $fieldPrefix }}health_failure_threshold" class="ui-label">
                {{ __('Outage confirmation') }}
            </label>
            <select
                id="{{ $fieldPrefix }}health_failure_threshold"
                name="health_failure_threshold"
                class="ui-input mt-2 w-full"
            >
                @foreach (\App\Models\Website::HEALTH_FAILURE_THRESHOLDS as $failures)
                    <option
                        value="{{ $failures }}"
                        @selected((int) old('health_failure_threshold', $website?->health_failure_threshold ?? \App\Models\Website::defaultHealthFailureThreshold()) === $failures)
                    >
                        {{ trans_choice('After :count consecutive failure|After :count consecutive failures', $failures, ['count' => $failures]) }}
                    </option>
                @endforeach
            </select>
            <p class="ui-help">
                {{ __('A successful check resets the count. An alert is created only when this threshold is first reached.') }}
            </p>
            <x-forms.errors name="health_failure_threshold"></x-forms.errors>
        </div>
    </fieldset>

    <div>
        <label for="{{ $fieldPrefix }}description" class="ui-label">
            {{ __('Description') }}
        </label>
        <div class="mt-2">
            <textarea
                id="{{ $fieldPrefix }}description"
                name="description"
                rows="3"
                class="ui-input w-full"
                placeholder="My website">{{ old('description') ?? ($website?->description ?? null) }}</textarea>
        </div>
        <p class="ui-help">
            {{ __('Brief description of your website') }}
        </p>
        <x-forms.errors name="description"></x-forms.errors>
    </div>
</div>
