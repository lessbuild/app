<x-signal.layouts.account :account="$account" :title="__('Members')" :description="__('People who can work in :account, and what they can do.', ['account' => $account->name])">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @foreach (['role', 'invitation'] as $field)
        @error($field)
            <x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>
        @enderror
    @endforeach

    @if ($overview->canManage)
        <x-signal.ui.settings-section :title="__('Invite someone')" :description="__('They get an email with a link that works for :days days. Inviting the same address again replaces the earlier invitation.', ['days' => \App\Domain\Accounts\Actions\InviteMember::EXPIRES_AFTER_DAYS])">
            <form method="POST" action="{{ route('account.invitations.store') }}" class="grid gap-5 p-4 sm:grid-cols-[minmax(0,1fr)_14rem] sm:items-end sm:p-6">
                @csrf
                <x-signal.ui.input-field name="email" :label="__('Email address')" type="email" autocomplete="off" required />
                <x-signal.ui.select-field name="role" :label="__('Role')" required>
                    @foreach ($overview->assignableRoles as $role)
                        <option value="{{ $role->value }}" @selected(old('role', 'member') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <div class="sm:col-span-2">
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Send invitation') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.settings-section>
    @endif

    <x-signal.ui.settings-section :title="__('People')" :description="trans_choice(':count person has access.|:count people have access.', count($overview->members), ['count' => count($overview->members)])">
        <ul class="divide-y divide-line" aria-label="{{ __('Account members') }}">
            @foreach ($overview->members as $member)
                <li class="flex flex-wrap items-center justify-between gap-4 p-4 sm:px-6">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-ink">
                            {{ $member->name }}
                            @if ($member->isYou)
                                <span class="font-normal text-muted">({{ __('you') }})</span>
                            @endif
                        </p>
                        <p class="mt-1 break-all text-xs text-muted">{{ $member->email }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($member->manageable)
                            <form method="POST" action="{{ route('account.members.update', $member->membershipId) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PUT')
                                <label for="role-{{ $member->membershipId }}" class="sr-only">{{ __('Role for :name', ['name' => $member->name]) }}</label>
                                <x-signal.ui.select id="role-{{ $member->membershipId }}" name="role">
                                    @foreach ($overview->assignableRoles as $role)
                                        <option value="{{ $role->value }}" @selected($member->role === $role)>{{ $role->label() }}</option>
                                    @endforeach
                                </x-signal.ui.select>
                                <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                            </form>
                            <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="remove-{{ $member->membershipId }}">{{ __('Remove') }}</x-signal.ui.button>
                            <x-signal.overlays.delete-confirmation
                                :id="'remove-'.$member->membershipId"
                                :route="route('account.members.destroy', $member->membershipId)"
                                :title="__('Remove :name?', ['name' => $member->name])"
                                :description="__('They lose access to :account straight away.', ['account' => $account->name])"
                                :warning="__('You can invite them again later.')"
                                :submit-label="__('Remove')"
                            />
                        @else
                            <x-signal.ui.badge :tone="$member->role === \App\Domain\Accounts\Enums\AccountRole::Owner ? 'accent' : 'neutral'">{{ $member->role->label() }}</x-signal.ui.badge>
                        @endif
                        @if ($member->isYou)
                            <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="leave-account">{{ __('Leave') }}</x-signal.ui.button>
                            <x-signal.overlays.delete-confirmation
                                id="leave-account"
                                :route="route('account.members.destroy', $member->membershipId)"
                                :title="__('Leave :account?', ['account' => $account->name])"
                                :description="__('You lose access until someone invites you again.')"
                                :warning="__('An account always keeps an owner, so the last owner can’t leave.')"
                                :submit-label="__('Leave')"
                            />
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-signal.ui.settings-section>

    @if ($overview->canManage)
        <x-signal.ui.settings-section :title="__('Pending invitations')" :description="__('Invitations that haven’t been accepted yet.')">
            @if ($overview->invitations === [])
                <p class="p-4 text-sm text-muted sm:p-6">{{ __('No pending invitations.') }}</p>
            @else
                <ul class="divide-y divide-line" aria-label="{{ __('Pending invitations') }}">
                    @foreach ($overview->invitations as $invitation)
                        <li class="flex flex-wrap items-center justify-between gap-4 p-4 sm:px-6">
                            <div class="min-w-0">
                                <p class="break-all text-sm font-bold text-ink">{{ $invitation->email }}</p>
                                <p class="mt-1 text-xs text-muted">
                                    {{ $invitation->role->label() }}
                                    @if ($invitation->invitedBy) · {{ __('Invited by :name', ['name' => $invitation->invitedBy]) }} @endif
                                    · {{ __('Expires :time', ['time' => $invitation->expiresAt->diffForHumans()]) }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('account.invitations.destroy', $invitation->id) }}">
                                @csrf
                                @method('DELETE')
                                <x-signal.ui.button type="submit" variant="quiet" size="sm" :aria-label="__('Revoke the invitation for :email', ['email' => $invitation->email])">{{ __('Revoke') }}</x-signal.ui.button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-signal.ui.settings-section>
    @endif

    <x-signal.ui.settings-section :title="__('What each role can do')" :description="__('Roles apply to the whole account.')">
        <dl class="divide-y divide-line">
            @foreach (\App\Domain\Accounts\Enums\AccountRole::cases() as $role)
                <div class="grid gap-1 p-4 sm:grid-cols-[10rem_minmax(0,1fr)] sm:px-6">
                    <dt class="text-sm font-bold text-ink">{{ $role->label() }}</dt>
                    <dd class="text-sm text-muted">{{ $role->description() }}</dd>
                </div>
            @endforeach
        </dl>
    </x-signal.ui.settings-section>
</x-signal.layouts.account>
