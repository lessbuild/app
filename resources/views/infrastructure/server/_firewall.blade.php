{{-- The Firewall tab: ports opened in ufw beyond SSH and the web ports set up with the server. --}}
<x-signal.ui.settings-section :title="__('Firewall')" :description="__('Everything else is closed. SSH (and ports 80 and 443 on web servers) were opened when the server was set up; open more here, for everyone or only one address or network.')">
    <div class="grid gap-4 p-4 sm:p-6">
        @forelse ($firewallRules as $task)
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                <div class="min-w-0">
                    <p class="font-bold text-ink">{{ $task->name }}</p>
                    <p class="text-xs text-muted"><span class="font-mono">{{ $task->port }}/{{ $task->protocol }}</span> · {{ __('from :source', ['source' => $task->from()]) }}</p>
                    @include('infrastructure.server._task-status')
                </div>
                @include('infrastructure.server._task-actions', ['kind' => 'firewall-rules', 'editId' => 'edit-rule-'.$task->id])
                <x-signal.overlays.form-modal :id="'edit-rule-'.$task->id" :title="__('Edit firewall rule')" :action="route('infrastructure.servers.tasks.update', [$project, $server->id, 'firewall-rules', $task->id])" method="PUT" :submit="__('Save')" form-class="grid items-start gap-5 sm:grid-cols-2">
                    @include('infrastructure.server._firewall-fields', ['rule' => $task, 'prefix' => 'edit-rule-'.$task->id])
                </x-signal.overlays.form-modal>
            </div>
        @empty
            <p class="text-sm text-muted">{{ __('No extra ports are open.') }}</p>
        @endforelse
        <div><x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-rule'])" variant="secondary" size="sm" data-modal-trigger="add-rule">{{ __('Open a port') }}</x-signal.ui.button></div>
        <x-signal.overlays.form-modal id="add-rule" :title="__('Open a port')" :action="route('infrastructure.servers.tasks.store', [$project, $server->id, 'firewall-rules'])" :submit="__('Open port')" form-class="grid items-start gap-5 sm:grid-cols-2">
            @include('infrastructure.server._firewall-fields', ['rule' => null, 'prefix' => 'add-rule'])
        </x-signal.overlays.form-modal>
    </div>
</x-signal.ui.settings-section>

<x-signal.ui.settings-section :title="__('Private network')" :description="$server->private_ip ? __('This server’s private IP is :ip. Traffic between servers at the same provider and region can stay on the private network; letting your other servers in opens every TCP port to their private IPs only.', ['ip' => $server->private_ip]) : __('This server has no private IP. Most providers give servers in the same region one automatically.')">
    <form method="POST" action="{{ route('infrastructure.servers.private-network', [$project, $server->id]) }}" class="flex flex-wrap items-center gap-3 p-4 sm:p-6">
        @csrf
        @method('PUT')
        <input type="hidden" name="trust" value="{{ $server->trust_private_network ? '0' : '1' }}">
        <p class="text-sm text-muted">{{ $server->trust_private_network ? __('Your other servers in this region are let in.') : __('Your other servers aren’t let in yet.') }}</p>
        @error('trust')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
        <x-signal.ui.button type="submit" variant="secondary" size="sm" :disabled="$server->private_ip === null">{{ $server->trust_private_network ? __('Stop letting them in') : __('Let my other servers in') }}</x-signal.ui.button>
    </form>
</x-signal.ui.settings-section>
