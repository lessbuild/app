<div class="px-4 py-5 bg-primary space-y-6 sm:p-6">

    <div class="col-span-3 sm:col-span-2">
        <label for="server_id" class="block text-sm font-medium text-primary">
            {{ __('Server') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <select id="server_id" name="server_id" class="input secondary rounded-sm" required>
                @foreach($servers as $server)
                    <option value="{{ $server->id }}"
                        @selected((string) old('server_id', $website->server_id ?? '') === (string) $server->id)
                    >
                        {{ $server->label }} ({{ str($server->type->value)->replace('-', ' ')->title() }})
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="server_id"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="name" class="block text-sm font-medium text-primary">
            {{ __('Website Name') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <input
                value="{{ old('name') ?? ($website->name ?? null) }}"
                type="text"
                name="name"
                id="name"
                class="input secondary rounded-sm"
                placeholder="Example: Deployer">
        </div>
        <x-forms.errors name="name"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="url" class="block text-sm font-medium text-primary">
            {{ __('Website URL') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-primary bg-tertiary text-primary text-sm">
                http://
            </span>
            <input
                value="{{ old('url') ?? ($website->url ?? null) }}"
                type="text"
                name="url"
                id="url"
                class="input secondary rounded-none rounded-r-md"
                placeholder="www.example.com">
        </div>
        <x-forms.errors name="url"></x-forms.errors>
    </div>

    <div>
        <label for="environment" class="block text-sm font-medium text-primary">
            {{ __('Environment') }}
        </label>
        <div class="mt-1">
            <textarea
                id="environment"
                name="environment"
                rows="3"
                class="input secondary rounded-sm"
                placeholder="APP_ENV=production....">{{ old('environment') ?? ($website->environment ?? null) }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Your environment file contents') }}
        </p>
        <x-forms.errors name="environment"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="release_retention" class="block text-sm font-medium text-primary">
            {{ __('Retained releases') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <input
                value="{{ old('release_retention', $website->release_retention ?? 5) }}"
                type="number"
                name="release_retention"
                id="release_retention"
                min="2"
                max="20"
                step="1"
                inputmode="numeric"
                class="input secondary rounded-sm"
            >
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Keep between 2 and 20 releases on the server for rollback and recovery.') }}
        </p>
        <x-forms.errors name="release_retention"></x-forms.errors>
    </div>

    <div class="rounded-lg border border-primary p-4">
        <div class="flex items-start gap-3">
            <input type="hidden" name="health_check_enabled" value="0">
            <input
                id="health_check_enabled"
                name="health_check_enabled"
                type="checkbox"
                value="1"
                class="mt-1 rounded-sm border-primary"
                @checked((bool) old('health_check_enabled', $website->health_check_enabled ?? false))
            >
            <div>
                <label for="health_check_enabled" class="block text-sm font-medium text-primary">
                    {{ __('Verify website health after deployment') }}
                </label>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('A failed check restores the previous release symlink. Database migrations are not rolled back.') }}
                </p>
            </div>
        </div>

        <div class="mt-4">
            <label for="health_check_path" class="block text-sm font-medium text-primary">
                {{ __('Health check path') }}
            </label>
            <div class="mt-1 flex rounded-md shadow-xs">
                <span class="inline-flex items-center rounded-l-md border border-r-0 border-primary bg-tertiary px-3 text-sm text-primary">
                    http://{{ old('url', $website->url ?? __('website')) }}
                </span>
                <input
                    value="{{ old('health_check_path', $website->health_check_path ?? '/') }}"
                    type="text"
                    name="health_check_path"
                    id="health_check_path"
                    class="input secondary rounded-none rounded-r-md"
                    placeholder="/health"
                >
            </div>
            <p class="mt-2 text-sm text-secondary">
                {{ __('Redirects are followed; the final response must be successful.') }}
            </p>
            <x-forms.errors name="health_check_enabled"></x-forms.errors>
            <x-forms.errors name="health_check_path"></x-forms.errors>
        </div>

        <div class="mt-4 flex items-start gap-3 border-t border-primary pt-4">
            <input type="hidden" name="health_monitoring_enabled" value="0">
            <input
                id="health_monitoring_enabled"
                name="health_monitoring_enabled"
                type="checkbox"
                value="1"
                class="mt-1 rounded-sm border-primary"
                @checked((bool) old('health_monitoring_enabled', $website->health_monitoring_enabled ?? true))
            >
            <div>
                <label for="health_monitoring_enabled" class="block text-sm font-medium text-primary">
                    {{ __('Automatically monitor website health') }}
                </label>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('Run scheduled checks and alert on outages. Deployment checks and manual checks remain available when scheduled monitoring is paused.') }}
                </p>
            </div>
        </div>
        <x-forms.errors name="health_monitoring_enabled"></x-forms.errors>

        <div class="mt-4 border-t border-primary pt-4">
            <label for="health_check_interval_minutes" class="block text-sm font-medium text-primary">
                {{ __('Automatic check interval') }}
            </label>
            <select
                id="health_check_interval_minutes"
                name="health_check_interval_minutes"
                class="input secondary mt-1 rounded-sm"
            >
                @foreach (\App\Models\Website::HEALTH_CHECK_INTERVALS as $minutes)
                    <option
                        value="{{ $minutes }}"
                        @selected((int) old('health_check_interval_minutes', $website->health_check_interval_minutes ?? \App\Models\Website::DEFAULT_HEALTH_CHECK_INTERVAL_MINUTES) === $minutes)
                    >
                        {{ trans_choice('Every :count minute|Every :count minutes', $minutes, ['count' => $minutes]) }}
                    </option>
                @endforeach
            </select>
            <p class="mt-2 text-sm text-secondary">
                {{ __('Applies to scheduled monitoring only. Manual and post-deployment checks can still run immediately.') }}
            </p>
            <x-forms.errors name="health_check_interval_minutes"></x-forms.errors>
        </div>

        <div class="mt-4 border-t border-primary pt-4">
            <label for="health_failure_threshold" class="block text-sm font-medium text-primary">
                {{ __('Outage confirmation') }}
            </label>
            <select
                id="health_failure_threshold"
                name="health_failure_threshold"
                class="input secondary mt-1 rounded-sm"
            >
                @foreach (\App\Models\Website::HEALTH_FAILURE_THRESHOLDS as $failures)
                    <option
                        value="{{ $failures }}"
                        @selected((int) old('health_failure_threshold', $website->health_failure_threshold ?? \App\Models\Website::defaultHealthFailureThreshold()) === $failures)
                    >
                        {{ trans_choice('After :count consecutive failure|After :count consecutive failures', $failures, ['count' => $failures]) }}
                    </option>
                @endforeach
            </select>
            <p class="mt-2 text-sm text-secondary">
                {{ __('A successful check resets the count. An alert is created only when this threshold is first reached.') }}
            </p>
            <x-forms.errors name="health_failure_threshold"></x-forms.errors>
        </div>
    </div>

    <div>
        <label for="description" class="block text-sm font-medium text-primary">
            {{ __('Description') }}
        </label>
        <div class="mt-1">
            <textarea
                id="description"
                name="description"
                rows="3"
                class="input secondary rounded-sm"
                placeholder="My website">{{ old('description') ?? ($website->description ?? null) }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Brief description of your website') }}
        </p>
        <x-forms.errors name="description"></x-forms.errors>
    </div>
</div>
