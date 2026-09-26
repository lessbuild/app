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
            @if ($canManageMembers)
                <x-signal.ui.card class="mb-6 p-5 sm:p-6">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="ui-eyebrow">{{ __('Separate app seats') }}</p>
                            <h2 class="mt-1 text-base font-extrabold text-ink">{{ __('Application access') }}</h2>
                        </div>
                        <p class="text-xs text-muted">{{ __('Each app uses only its own plan seats. Workspace invitations do not reserve app seats.') }}</p>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        @foreach ($productAccessSummary as $product => $summary)
                            <div class="rounded-xl border border-line bg-surface-soft p-4">
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-extrabold text-ink">{{ $summary['label'] }}</h3>
                                    @if ($summary['plan_ready'])
                                        <x-signal.ui.badge tone="success">{{ __('Plan ready') }}</x-signal.ui.badge>
                                    @else
                                        <x-signal.ui.badge tone="warning">{{ __('Plan needed') }}</x-signal.ui.badge>
                                    @endif
                                </div>
                                <p class="mt-2 text-xs text-muted">
                                    @if (! $summary['plan_ready'])
                                        {{ __('Seat limit is not available for this app yet.') }}
                                    @elseif ($summary['limit'] === null)
                                        {{ __(':used active seats · unlimited', ['used' => $summary['used']]) }}
                                    @else
                                        {{ __(':used of :limit seats in use', ['used' => $summary['used'], 'limit' => $summary['limit']]) }}
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs leading-5 text-muted">{{ __('Member access maps to each app’s own roles: Developer in Deployer, Member in Monitor, and Viewer in Analytics. App administrators remain owner-managed.') }}</p>
                </x-signal.ui.card>
            @endif

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
                        <div class="grid gap-4 px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
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

                            <div class="grid gap-2 sm:grid-cols-3">
                                @foreach ($productAccessSummary as $product => $summary)
                                    @php
                                        $grant = $membership->productAccess->firstWhere('product', $product);
                                        $activeGrant = $grant
                                            && $grant->status === 'active'
                                            && $grant->revoked_at === null
                                            && ($grant->expires_at === null || $grant->expires_at->isFuture());
                                        $grantRole = $activeGrant ? $grant->role : 'none';
                                        $cleanupPending = $grant && ($grant->metadata['local_membership_cleanup_pending'] ?? false);
                                        $grantRoleLabel = match ($grantRole) {
                                            'none' => __('No access'),
                                            'owner' => __('Owner access'),
                                            'admin' => __('Administrator'),
                                            'member' => match ($product) {
                                                'deployer' => __('Developer'),
                                                'analytics' => __('Viewer'),
                                                default => __('Member'),
                                            },
                                            default => str($grantRole)->headline(),
                                        };
                                        $seatAvailable = $summary['plan_ready']
                                            && ($summary['limit'] === null || $summary['used'] < $summary['limit'] || $activeGrant);
                                        $canEditGrant = $grantRole !== 'owner'
                                            && $canManageMembers
                                            && ($canManageProductAdmins || ! in_array($grantRole, ['admin', 'owner'], true));
                                    @endphp
                                    <div class="rounded-xl border border-line bg-surface-soft p-3">
                                        @if ($canEditGrant)
                                            <form method="POST" action="{{ route('core.workspace.team.memberships.products.update', [$workspace, $membership, $product]) }}" class="grid gap-2">
                                                @csrf
                                                @method('PUT')
                                                <label class="text-xs font-bold text-muted" for="product-access-{{ $membership->id }}-{{ $product }}">{{ $summary['label'] }} {{ __('role') }}</label>
                                                <x-signal.ui.select
                                                    id="product-access-{{ $membership->id }}-{{ $product }}"
                                                    name="role"
                                                    aria-label="{{ __(':product role for :name', ['product' => $summary['label'], 'name' => $membership->user?->name ?: $membership->user?->email]) }}"
                                                >
                                                    <option value="none" @selected($grantRole === 'none')>{{ __('No access') }}</option>
                                                    <option value="viewer" @selected($grantRole === 'viewer') @disabled(! $seatAvailable && $grantRole !== 'viewer')>{{ __('Viewer') }}</option>
                                                    <option value="member" @selected($grantRole === 'member') @disabled(! $seatAvailable && $grantRole !== 'member')>
                                                        {{ match ($product) { 'deployer' => __('Developer'), 'analytics' => __('Viewer'), default => __('Member') } }}
                                                    </option>
                                                    @if ($canManageProductAdmins)
                                                        <option value="admin" @selected($grantRole === 'admin') @disabled(! $seatAvailable && $grantRole !== 'admin')>{{ __('Administrator') }}</option>
                                                    @endif
                                                </x-signal.ui.select>
                                                <x-signal.ui.button type="submit" variant="secondary" class="justify-center">{{ $cleanupPending ? __('Retry app cleanup') : __('Save app access') }}</x-signal.ui.button>
                                                @if ($activeGrant)
                                                    <p class="text-xs leading-5 text-muted">{{ __('Changing this app role keeps :used seats in use. Choosing No access frees this app seat.', ['used' => $summary['used']]) }}</p>
                                                @elseif ($seatAvailable)
                                                    @if ($summary['limit'] === null)
                                                        <p class="text-xs leading-5 text-muted">{{ __('Granting any role other than No access adds one active :product seat. This plan is unlimited.', ['product' => $summary['label']]) }}</p>
                                                    @else
                                                        <p class="text-xs leading-5 text-muted">{{ __('Granting any role other than No access will use :projected of :limit :product seats.', ['projected' => $summary['used'] + 1, 'limit' => $summary['limit'], 'product' => $summary['label']]) }}</p>
                                                    @endif
                                                @endif
                                                @if ($cleanupPending)
                                                    <p class="text-xs leading-5 text-warning">{{ __('Core access is already blocked; only app-local membership cleanup needs review.') }}</p>
                                                @endif
                                                @if (! $summary['plan_ready'] && ! $activeGrant)
                                                    <p class="text-xs leading-5 text-muted">{{ __('This app needs an active plan with a defined seat limit before access can be granted.') }}</p>
                                                @elseif (! $seatAvailable && ! $activeGrant)
                                                    <p class="text-xs leading-5 text-muted">{{ __('This app has no available seats.') }}</p>
                                                @endif
                                            </form>
                                        @elseif ($grantRole === 'owner' && $canManageMembers)
                                            <p class="text-xs font-bold text-muted">{{ $summary['label'] }}</p>
                                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                                <x-signal.ui.badge tone="accent">{{ $grantRoleLabel }}</x-signal.ui.badge>
                                                @if ($canManageProductAdmins)
                                                    <form method="POST" action="{{ route('core.workspace.team.memberships.products.update', [$workspace, $membership, $product]) }}" onsubmit='return confirm(@js(__('Revoke this person’s app access through Buildpusher? Their product ownership record will remain unchanged.')))'>
                                                        @csrf
                                                        @method('PUT')
                                                        <x-signal.ui.input type="hidden" name="role" value="none" :restore="false" />
                                                        <x-signal.ui.button type="submit" variant="danger">{{ __('Revoke access') }}</x-signal.ui.button>
                                                    </form>
                                                @endif
                                            </div>
                                        @else
                                            <p class="text-xs font-bold text-muted">{{ $summary['label'] }}</p>
                                            <x-signal.ui.badge :tone="$grantRole === 'none' ? 'neutral' : 'success'" class="mt-2">
                                                {{ $grantRoleLabel }}
                                            </x-signal.ui.badge>
                                        @endif
                                    </div>
                                @endforeach
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

            @if ($canManageMembers)
                <section aria-labelledby="workspace-team-history-title" class="mt-6">
                    <x-signal.ui.card class="p-5 sm:p-6">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="ui-eyebrow">{{ __('Access history') }}</p>
                                <h2 id="workspace-team-history-title" class="mt-1 text-base font-extrabold text-ink">{{ __('Recent team changes') }}</h2>
                            </div>
                            <x-signal.ui.badge tone="neutral">{{ $auditEvents->count() }}</x-signal.ui.badge>
                        </div>

                        @if ($auditEvents->isEmpty())
                            <x-signal.ui.empty-state :title="__('No team changes recorded')" :description="__('Role and membership changes will appear here for workspace managers.')" icon="clock" class="mt-4" />
                        @else
                            <ol class="mt-4 divide-y divide-line">
                                @foreach ($auditEvents as $event)
                                    @php
                                        $actorName = $event->actor?->name ?: $event->actor?->email ?: __('Deleted account');
                                        $subjectName = $event->subject?->name ?: $event->subject?->email ?: __('Deleted account');
                                    @endphp
                                    <li class="py-3 first:pt-0 last:pb-0">
                                        @if ($event->event === 'role_changed')
                                            <p class="text-sm leading-6 text-ink">{{ __(':actor changed :subject’s workspace role from :previous to :current.', ['actor' => $actorName, 'subject' => $subjectName, 'previous' => str($event->previous_role)->headline(), 'current' => str($event->new_role)->headline()]) }}</p>
                                        @elseif ($event->event === 'product_access_changed')
                                            @php
                                                $productName = str($event->metadata['product'] ?? 'application')->headline();
                                            @endphp
                                            <p class="text-sm leading-6 text-ink">
                                                @if ($event->new_role === null)
                                                    {{ __(':actor revoked :product access for :subject.', ['actor' => $actorName, 'product' => $productName, 'subject' => $subjectName]) }}
                                                @elseif ($event->previous_role === null)
                                                    {{ __(':actor granted :subject the :role role in :product.', ['actor' => $actorName, 'subject' => $subjectName, 'role' => str($event->new_role)->headline(), 'product' => $productName]) }}
                                                @else
                                                    {{ __(':actor changed :subject’s :product role from :previous to :current.', ['actor' => $actorName, 'subject' => $subjectName, 'product' => $productName, 'previous' => str($event->previous_role)->headline(), 'current' => str($event->new_role)->headline()]) }}
                                                @endif
                                            </p>
                                            @if (! empty($event->metadata['local_membership_cleanup_pending']))
                                                <p class="mt-1 text-xs leading-5 text-muted">{{ __('Core access is blocked. App-local membership cleanup needs review for :product.', ['product' => $productName]) }}</p>
                                            @endif
                                        @elseif (in_array($event->event, ['product_access_cleanup_pending', 'product_access_cleanup_completed'], true))
                                            @php
                                                $productName = str($event->metadata['product'] ?? 'application')->headline();
                                            @endphp
                                            <p class="text-sm leading-6 text-ink">
                                                @if ($event->event === 'product_access_cleanup_completed')
                                                    {{ __(':actor completed local :product membership cleanup for :subject.', ['actor' => $actorName, 'product' => $productName, 'subject' => $subjectName]) }}
                                                @else
                                                    {{ __(':actor retried local :product membership cleanup for :subject; review is still needed.', ['actor' => $actorName, 'product' => $productName, 'subject' => $subjectName]) }}
                                                @endif
                                            </p>
                                        @elseif ($event->event === 'membership_revoked')
                                            <p class="text-sm leading-6 text-ink">{{ __(':actor removed :subject from the workspace and revoked their Core product and project access.', ['actor' => $actorName, 'subject' => $subjectName]) }}</p>
                                            @if (! empty($event->metadata['pending_local_membership_cleanup']))
                                                <p class="mt-1 text-xs leading-5 text-muted">{{ __('Core access is blocked. App-local membership cleanup needs review for :products.', ['products' => collect($event->metadata['pending_local_membership_cleanup'])->map(fn ($product) => str($product)->headline())->join(', ')]) }}</p>
                                            @endif
                                        @elseif (in_array($event->event, ['project_access_granted', 'project_access_revoked'], true))
                                            <p class="text-sm leading-6 text-ink">
                                                @if ($event->event === 'project_access_granted')
                                                    {{ __(':actor granted :subject access to :project.', ['actor' => $actorName, 'subject' => $subjectName, 'project' => $event->metadata['project_name'] ?? __('a project')]) }}
                                                @else
                                                    {{ __(':actor revoked :subject’s access to :project.', ['actor' => $actorName, 'subject' => $subjectName, 'project' => $event->metadata['project_name'] ?? __('a project')]) }}
                                                @endif
                                            </p>
                                        @else
                                            <p class="text-sm leading-6 text-ink">{{ __('A workspace membership changed.') }}</p>
                                        @endif
                                        <time class="mt-1 block text-xs text-muted" datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->format('M j, Y g:i A') }}</time>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </x-signal.ui.card>
                </section>
            @endif
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
