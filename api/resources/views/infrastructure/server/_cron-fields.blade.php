{{-- A cron job's fields; $job is null for a new one. --}}
<div class="sm:col-span-2"><x-signal.ui.input-field :id="$prefix.'-command'" name="command" :label="__('Command')" :value="$job?->command" placeholder="php /home/{{ $server->name }}/example.com/current/artisan schedule:run" maxlength="1000" required /></div>
<x-signal.ui.input-field :id="$prefix.'-frequency'" name="frequency" :label="__('Schedule (cron)')" :value="$job?->frequency ?? '* * * * *'" :description="__('Every minute is * * * * *; every night at 3 is 0 3 * * *.')" list="cron-presets" maxlength="100" required />
<x-signal.ui.select-field :id="$prefix.'-user'" name="user" :label="__('Run as')">
    <option value="{{ $server->name }}" @selected(($job?->user ?? $server->name) === $server->name)>{{ $server->name }}</option>
    <option value="root" @selected($job?->user === 'root')>root</option>
</x-signal.ui.select-field>
