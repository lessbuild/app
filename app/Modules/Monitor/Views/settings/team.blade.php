@extends('monitor::layouts.app')
@section('title', 'Team access')
@section('breadcrumb', 'Team access')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="Workspace settings" title="Your team, connected." :description="'Manage membership and access for '.$workspace->name.'.'" />
    @php($roleOptions = array_combine(\App\Modules\Monitor\Models\Workspace::ASSIGNABLE_ROLES, array_map('ucfirst', \App\Modules\Monitor\Models\Workspace::ASSIGNABLE_ROLES)))
    <div class="grid gap-6 xl:grid-cols-2">
        @can('update', $workspace)
        <x-signal.ui.panel as="section" class="p-6">
            <h2 class="mb-5 font-bold">Workspace details</h2>
            <form method="POST" action="{{ route('monitor.workspaces.update', $workspace) }}" class="space-y-4">
                @csrf @method('PATCH')
                <x-monitor::ui.input name="name" label="Workspace name" :value="$workspace->name" maxlength="120" required />
                <x-monitor::ui.button>Save changes</x-monitor::ui.button>
            </form>
        </x-signal.ui.panel>
        <x-signal.ui.panel as="section" class="p-6">
            <h2 class="font-bold">Invite a teammate</h2>
            <p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">Admins manage applications and members. Members investigate issues. Viewers have read-only access. Only the owner manages billing.</p>
            @if($seatCapacity['at_limit'])
                @if(! $seatCapacity['plan_available'] || ! $seatCapacity['limit_configured'])
                    <x-signal.ui.alert as="p" tone="info" class="border-info/30 bg-info-soft block mt-5 p-4 text-xs leading-5 text-info dark:text-info">Monitor could not verify this workspace’s seat allowance. Reconcile its Core subscription before inviting a teammate.</x-signal.ui.alert>
                @else
                    <x-signal.ui.alert as="p" tone="warning" class="block mt-5 p-4 text-xs leading-5 text-warning dark:text-warning">Your {{ $workspacePlan['name'] }} plan has reached its {{ $seatCapacity['limit'] }}-seat allowance. Upgrade the workspace plan or remove a teammate before inviting another.</x-signal.ui.alert>
                @endif
            @else
                <form method="POST" action="{{ route('monitor.invitations.store', $workspace) }}" class="mt-5 space-y-4">
                    @csrf
                    <x-monitor::ui.input name="email" type="email" label="Their email address" required />
                    <x-monitor::ui.select id="invite-role" name="role" label="Role" value="member" :options="$roleOptions" />
                    <x-monitor::ui.button>Send invitation</x-monitor::ui.button>
                </form>
            @endif
        </x-signal.ui.panel>
        @endcan
    </div>
    <x-signal.ui.panel as="section" class="overflow-hidden">
        <div class="border-b border-line px-6 py-4 dark:border-line"><h2 class="font-bold">Members <span class="ml-2 text-xs text-subtle">{{ $members->count() }}{{ ! $seatCapacity['plan_available'] || ! $seatCapacity['limit_configured'] ? ' · plan unverified' : ($seatCapacity['limit'] === null ? ' · unlimited' : ' / '.$seatCapacity['limit']) }} seats</span></h2></div>
        <div class="divide-y divide-line dark:divide-line">
            @foreach($members as $member)
                <div class="flex flex-col justify-between gap-4 px-6 py-4 sm:flex-row sm:items-center">
                    <div><p class="text-sm font-semibold">{{ $member->name }} @if($member->id === auth()->id())<span class="ml-1 text-xs font-normal text-subtle">(you)</span>@endif</p><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $member->email }}</p></div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if($member->id !== $workspace->owner_id && auth()->user()->can('update', $workspace))
                            <form method="POST" action="{{ route('monitor.members.update', [$workspace, $member]) }}" class="flex items-center gap-2">
                                @csrf @method('PATCH')
                                <x-monitor::ui.select :id="'member-role-'.$member->id" name="role" :label="'Role for '.$member->name" :value="$member->pivot->role" :options="$roleOptions" :restore="false" :error-key="false" hide-label />
                                <x-monitor::ui.button variant="secondary">Save role</x-monitor::ui.button>
                            </form>
                            <form method="POST" action="{{ route('monitor.members.destroy', [$workspace, $member]) }}">@csrf @method('DELETE')<x-monitor::ui.button variant="secondary">Remove</x-monitor::ui.button></form>
                        @else
                            <x-monitor::ui.badge tone="violet">{{ ucfirst($member->pivot->role) }}</x-monitor::ui.badge>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-signal.ui.panel>
    @can('update', $workspace)
    <x-signal.ui.panel as="section" class="overflow-hidden">
        <h2 class="border-b border-line px-6 py-4 font-bold dark:border-line">Pending invitations</h2>
        <div class="divide-y divide-line dark:divide-line">
            @forelse($invitations as $invitation)
                <div class="flex flex-col justify-between gap-3 px-6 py-4 sm:flex-row sm:items-center">
                    <div><p class="text-sm font-semibold">{{ $invitation->email }}</p><p class="mt-1 text-xs text-muted dark:text-subtle">{{ ucfirst($invitation->role) }} · {{ $invitation->expires_at->isPast() ? 'Expired' : 'Expires '.$invitation->expires_at->diffForHumans() }}</p></div>
                    <form method="POST" action="{{ route('monitor.invitations.destroy', [$workspace, $invitation]) }}">@csrf @method('DELETE')<x-monitor::ui.button variant="secondary">Revoke</x-monitor::ui.button></form>
                </div>
            @empty
                <p class="px-6 py-8 text-sm text-muted dark:text-subtle">No pending invitations. Invite someone above to get started.</p>
            @endforelse
        </div>
    </x-signal.ui.panel>
    @endcan
</div>
@endsection
