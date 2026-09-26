@extends('analytics::layouts.app')

@section('content')
    <div class="space-y-8">
        <x-signal.ui.page-header
            eyebrow="{{ $workspace->name }}"
            title="Team access"
            description="Invite collaborators with the least access they need. Invitations expire automatically."
        >
            <x-slot:actions>
                @if ($workspaceRole?->canManageMembers())
                    <x-signal.ui.button href="{{ route('analytics.workspaces.data', $workspace) }}" variant="secondary">{{ __('Data & privacy') }}</x-signal.ui.button>
                @endif
                <x-signal.ui.button href="{{ route('analytics.dashboard') }}" variant="secondary">Overview</x-signal.ui.button>
            </x-slot:actions>
        </x-signal.ui.page-header>

        @if (session('status'))
            <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
        @endif

        @if ($workspaceRole?->canManageMembers())
            <x-signal.ui.panel as="form" class="grid gap-4 p-6 sm:grid-cols-[1fr_10rem_auto] sm:items-end" method="POST" :action="route('analytics.workspaces.invitations.store', $workspace)">
                @csrf
                <x-signal.ui.field label="Email address" name="email" required>
                    <x-signal.ui.input name="email" type="email" :value="old('email')" placeholder="teammate@example.com" required autocomplete="email" />
                </x-signal.ui.field>
                <x-signal.ui.field label="Role" name="role">
                    <x-signal.ui.select name="role">
                        <option value="viewer">Viewer</option>
                        <option value="admin">Admin</option>
                    </x-signal.ui.select>
                </x-signal.ui.field>
                <x-signal.ui.button type="submit" variant="primary">Send invite</x-signal.ui.button>
            </x-signal.ui.panel>
        @endif

        <x-signal.ui.panel as="section" class="overflow-hidden">
            <div class="border-b border-line px-6 py-4"><h2 class="font-extrabold">Members</h2></div>
            @foreach ($members as $member)
                @php($isCurrentMember = in_array((string) $member->getKey(), $currentProductUserIds, true))
                <div class="flex flex-col justify-between gap-4 border-b border-line p-5 last:border-b-0 sm:flex-row sm:items-center">
                    <div class="min-w-0">
                        <p class="truncate font-bold">{{ $member->name }} @if ($isCurrentMember)<span class="text-xs font-normal text-muted">(you)</span>@endif</p>
                        <p class="truncate text-sm text-muted">{{ $member->email }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-signal.ui.badge>{{ ucfirst($member->pivot->role) }}</x-signal.ui.badge>
                        @if ($workspaceRole === \App\Modules\Analytics\Enums\WorkspaceRole::Owner && ! $isCurrentMember && $workspace->roleFor((int) $member->getKey()) !== \App\Modules\Analytics\Enums\WorkspaceRole::Owner)
                            <form method="POST" action="{{ route('analytics.workspaces.members.update', [$workspace, $member]) }}">
                                @csrf
                                @method('PUT')
                                <x-signal.ui.input type="hidden" name="role" :value="$member->pivot->role === 'admin' ? 'viewer' : 'admin'" />
                                <x-signal.ui.button type="submit" variant="ghost" class="text-xs">Make {{ $member->pivot->role === 'admin' ? 'viewer' : 'admin' }}</x-signal.ui.button>
                            </form>
                            <form method="POST" action="{{ route('analytics.workspaces.members.destroy', [$workspace, $member]) }}">
                                @csrf
                                @method('DELETE')
                                <x-signal.ui.button type="submit" variant="ghost" class="text-xs text-danger">Remove</x-signal.ui.button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </x-signal.ui.panel>

        @if ($invitations->isNotEmpty())
            <x-signal.ui.panel as="section" class="overflow-hidden">
                <div class="border-b border-line px-6 py-4"><h2 class="font-extrabold">Pending invitations</h2></div>
                @foreach ($invitations as $invitation)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-5 last:border-b-0">
                        <div><p class="font-bold">{{ $invitation->email }}</p><p class="mt-1 text-xs text-muted">{{ ucfirst($invitation->role) }} · expires {{ $invitation->expires_at->diffForHumans() }}</p></div>
                        <x-signal.ui.badge tone="warning">Pending</x-signal.ui.badge>
                    </div>
                @endforeach
            </x-signal.ui.panel>
        @endif
    </div>
@endsection
