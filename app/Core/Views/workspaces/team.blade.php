<x-signal.layouts.platform
    :title="__('Team access')"
    :description="__('Manage workspace members and invitations.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="collect()"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Team access')"
        :description="__('Workspace membership is shared across Buildpusher. Each app’s access and subscription remain separate.')"
    />

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)]">
        <section aria-labelledby="workspace-members-title">
            <x-signal.ui.card class="p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-5">
                    <div>
                        <h2 id="workspace-members-title" class="text-base font-extrabold text-ink">{{ __('Members') }}</h2>
                        <p class="mt-1 text-xs text-muted">{{ __(':count active workspace members', ['count' => $members->total()]) }}</p>
                    </div>
                    <x-signal.ui.badge tone="neutral">{{ __('Workspace roles') }}</x-signal.ui.badge>
                </div>

                <div class="divide-y divide-line">
                    @forelse ($members as $membership)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <x-signal.ui.avatar :name="$membership->user?->name ?: $membership->user?->email" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-ink">{{ $membership->user?->name ?: __('Unknown account') }}</p>
                                    <p class="truncate text-xs text-muted">{{ $membership->user?->email }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @if ($canManageRoles && $membership->role !== 'owner' && ($canAssignAdminRole || $membership->role !== 'admin'))
                                    <form method="POST" action="{{ route('core.workspace.team.memberships.role.update', [$workspace, $membership]) }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('PUT')
                                        <x-signal.ui.select name="role" aria-label="{{ __('Workspace role for :name', ['name' => $membership->user?->name ?: $membership->user?->email]) }}">
                                            @foreach (['admin' => __('Administrator'), 'billing' => __('Billing manager'), 'member' => __('Member'), 'viewer' => __('Viewer')] as $role => $label)
                                                @continue($role === 'admin' && ! $canAssignAdminRole)
                                                <option value="{{ $role }}" @selected($membership->role === $role)>{{ $label }}</option>
                                            @endforeach
                                        </x-signal.ui.select>
                                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Save role') }}</x-signal.ui.button>
                                    </form>
                                @else
                                    <x-signal.ui.badge :tone="$membership->role === 'owner' ? 'accent' : 'neutral'">{{ str($membership->role)->headline() }}</x-signal.ui.badge>
                                @endif

                                @if ($canManageMembers && $membership->role !== 'owner' && (string) $membership->user_id !== $actorUserId && ($canManageRoles || $membership->role !== 'admin'))
                                    <form method="POST" action="{{ route('core.workspace.team.memberships.destroy', [$workspace, $membership]) }}" onsubmit='return confirm(@js(__('Remove this person from the workspace? Their workspace product grants will also be revoked.')))'>
                                        @csrf
                                        @method('DELETE')
                                        <x-signal.ui.button type="submit" variant="danger">{{ __('Remove') }}</x-signal.ui.button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <x-signal.ui.empty-state :title="__('No active members')" :description="__('Active workspace members will appear here.')" icon="user-group" />
                    @endforelse
                </div>

                @if ($members->hasPages())
                    <div class="border-t border-line p-4">{{ $members->links() }}</div>
                @endif
            </x-signal.ui.card>
        </section>

        <div class="grid gap-6">
            @if ($canManageMembers)
                <section aria-labelledby="invite-member-title">
                    <x-signal.ui.card class="p-5 sm:p-6">
                        <p class="ui-eyebrow">{{ __('Workspace access') }}</p>
                        <h2 id="invite-member-title" class="mt-2 text-base font-extrabold text-ink">{{ __('Invite a teammate') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ __('The invitee must use this email address to accept. Product grants and billing seats are managed independently.') }}</p>

                        <form method="POST" action="{{ route('core.workspace.team.invitations.store', $workspace) }}" class="mt-5 grid gap-4">
                            @csrf
                            <x-signal.ui.field :label="__('Email address')" name="email" required>
                                <x-signal.ui.input name="email" type="email" autocomplete="email" required />
                            </x-signal.ui.field>
                            <x-signal.ui.field :label="__('Workspace role')" name="role" required>
                                <x-signal.ui.select name="role" required>
                                    <option value="member">{{ __('Member') }}</option>
                                    <option value="viewer">{{ __('Viewer') }}</option>
                                    <option value="billing">{{ __('Billing manager') }}</option>
                                    <option value="admin">{{ __('Administrator') }}</option>
                                </x-signal.ui.select>
                            </x-signal.ui.field>
                            <x-signal.ui.button variant="primary" type="submit" class="justify-center">{{ __('Send invitation') }}</x-signal.ui.button>
                        </form>
                    </x-signal.ui.card>
                </section>
            @endif

            <section aria-labelledby="workspace-invitations-title">
                <x-signal.ui.card class="p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="workspace-invitations-title" class="text-base font-extrabold text-ink">{{ __('Recent invitations') }}</h2>
                        <x-signal.ui.badge tone="neutral">{{ $invitations->count() }}</x-signal.ui.badge>
                    </div>

                    @if ($invitations->isEmpty())
                        <x-signal.ui.empty-state :title="__('No invitations yet')" :description="__('Workspace invitations will appear here after they are sent.')" icon="mail" class="mt-4" />
                    @else
                        <ul class="mt-4 divide-y divide-line">
                            @foreach ($invitations as $invitation)
                                @php
                                    $expired = $invitation->status === 'pending' && $invitation->expires_at?->isPast();
                                    $statusLabel = $expired ? __('Expired') : str($invitation->status)->headline();
                                    $statusTone = $expired ? 'danger' : match ($invitation->status) {
                                        'pending' => 'warning',
                                        'accepted' => 'success',
                                        default => 'neutral',
                                    };
                                @endphp
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-ink">{{ $invitation->email }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ str($invitation->role)->headline() }} · {{ __('Expires :date', ['date' => $invitation->expires_at?->toFormattedDateString()]) }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-signal.ui.badge :tone="$statusTone">{{ $statusLabel }}</x-signal.ui.badge>
                                        @if ($canManageMembers && $invitation->status === 'pending' && ! $expired)
                                            <form method="POST" action="{{ route('core.workspace.team.invitations.destroy', [$workspace, $invitation]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-signal.ui.button variant="danger" type="submit">{{ __('Revoke') }}</x-signal.ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-signal.ui.card>
            </section>
        </div>
    </div>
</x-signal.layouts.platform>
