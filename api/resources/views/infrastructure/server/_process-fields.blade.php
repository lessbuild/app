{{-- A process's fields; $process is null for a new one. --}}
<x-signal.ui.input-field :id="$prefix.'-name'" name="name" :label="__('Name')" :value="$process?->name" placeholder="Queue worker" maxlength="60" required />
<x-signal.ui.input-field :id="$prefix.'-processes'" name="processes" type="number" min="1" max="20" :label="__('Copies')" :value="$process?->processes ?? 1" required />
<div class="sm:col-span-2"><x-signal.ui.input-field :id="$prefix.'-command'" name="command" :label="__('Command')" :value="$process?->command" placeholder="php artisan queue:work --sleep=3 --tries=3" maxlength="1000" required /></div>
<x-signal.ui.input-field :id="$prefix.'-directory'" name="directory" :label="__('Folder')" :value="$process?->directory" placeholder="/home/{{ $server->name }}/example.com/current" maxlength="255" />
<x-signal.ui.select-field :id="$prefix.'-user'" name="user" :label="__('Run as')">
    <option value="{{ $server->name }}" @selected(($process?->user ?? $server->name) === $server->name)>{{ $server->name }}</option>
    <option value="root" @selected($process?->user === 'root')>root</option>
</x-signal.ui.select-field>
<x-signal.ui.input-field :id="$prefix.'-stop'" name="stop_wait_seconds" type="number" min="1" max="3600" :label="__('Time to finish when stopped (seconds)')" :value="$process?->stop_wait_seconds ?? 10" />
