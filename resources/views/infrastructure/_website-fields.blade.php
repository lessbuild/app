{{-- Website fields; $website is null when creating. --}}
<x-signal.ui.input-field name="name" :label="__('Name')" :value="$website?->name" maxlength="255" placeholder="Shop" required />
<x-signal.ui.select-field name="server_id" :label="__('Server')" required>
    @foreach ($hosts as $host)
        <option value="{{ $host->id }}" @selected((int) old('server_id', $website?->server_id) === $host->id)>{{ $host->label() }} · {{ $host->public_ip }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field name="url" :label="__('Domain')" :value="$website?->url" maxlength="255" placeholder="shop.example.com" :description="__('Point its DNS at the server; Caddy gets the certificate.')" required />
<x-signal.ui.input-field name="release_retention" type="number" min="2" max="20" :label="__('Releases to keep')" :value="$website?->release_retention ?? 5" />
<div class="sm:col-span-2">
    <x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$website?->description" rows="2" maxlength="2000" />
</div>
<x-signal.ui.select-field name="environment_id" :label="__('Serves environment')" :description="__('Link it to a project environment for deployments and health checks.')">
    <option value="">{{ __('Not linked') }}</option>
    @foreach ($environments as $environment)
        <option value="{{ $environment->id }}" @selected(old('environment_id', $website?->environment_id) === $environment->id)>{{ $environment->project->name }} / {{ $environment->name }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field name="health_check_path" :label="__('Health check path')" :value="$website?->health_check_path ?? '/'" maxlength="255" />
<div class="sm:col-span-2 flex flex-wrap items-end gap-5">
    <x-signal.ui.checkbox name="health_check_enabled" value="1" unchecked-value="0" :checked="(bool) ($website?->health_check_enabled ?? false)" :description="__('Checked by Monitoring in the linked environment, with its incidents and alerts.')">{{ __('Check the website’s health') }}</x-signal.ui.checkbox>
    <x-signal.ui.select-field name="health_check_interval_minutes" :label="__('Every')">
        @foreach (\App\Models\Website::HEALTH_CHECK_INTERVALS as $minutes)
            <option value="{{ $minutes }}" @selected((int) old('health_check_interval_minutes', $website?->health_check_interval_minutes ?? 5) === $minutes)>{{ trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]) }}</option>
        @endforeach
    </x-signal.ui.select-field>
    <x-signal.ui.select-field name="health_failure_threshold" :label="__('Incident after')">
        @foreach (\App\Models\Website::HEALTH_FAILURE_THRESHOLDS as $count)
            <option value="{{ $count }}" @selected((int) old('health_failure_threshold', $website?->health_failure_threshold ?? 3) === $count)>{{ trans_choice(':count failed check|:count failed checks', $count, ['count' => $count]) }}</option>
        @endforeach
    </x-signal.ui.select-field>
</div>
<div class="sm:col-span-2">
    <x-signal.ui.textarea-field name="env_file" :label="__('.env file')" :value="$website?->env_file" rows="6" class="font-mono" :restore="$website === null" :description="__('Stored encrypted and written to the server. Changing it sets the website up again.')" />
</div>
