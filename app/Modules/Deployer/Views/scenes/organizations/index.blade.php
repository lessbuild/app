<x-layouts.app>
    <x-signal.ui.page-header
        eyebrow="{{ __('Workspace administration') }}"
        icon="user-circle"
        :title="__('Workspace')"
        :description="__('Manage members, roles, and workspace access.')"
    >
        @if ($canManage)
            <x-slot:actions>
                <x-signal.ui.button
                    href="{{ route('organizations.index', ['dialog' => 'invite-member']) }}"
                    data-modal-trigger="organization-invite"
                    aria-controls="organization-invite"
                    aria-expanded="{{ request()->query('dialog') === 'invite-member' ? 'true' : 'false' }}"
                    variant="primary"
                >
                    {{ __('Invite member') }}
                </x-signal.ui.button>
            </x-slot:actions>
        @endif
    </x-signal.ui.page-header>

    @php
        $organizationDefaultErrorKeys = array_keys($errors->getBag('default')->getMessages());
        $hasOrganizationError = static fn (array $fields): bool => collect($organizationDefaultErrorKeys)->contains(
            static fn (string $key): bool => collect($fields)->contains(
                static fn (string $field): bool => $key === $field || str_starts_with($key, $field.'.'),
            ),
        );
        $securityPolicyOpen = $hasOrganizationError([
            'allowed_ip_ranges', 'allowed_email_domains', 'require_two_factor',
            'session_idle_minutes', 'sso_issuer', 'sso_client_id',
            'sso_client_secret', 'sso_enforced',
        ]);
        $notificationPreferencesOpen = $hasOrganizationError(['categories', 'recoveries']);
        $notificationPreferencesDialogId = 'organization-notification-preferences-dialog';
        $notificationPreferencesDialogOpen = request()->query('dialog') === $notificationPreferencesDialogId
            || $notificationPreferencesOpen;
        $notificationPreferencesDialogUrl = route('organizations.index', ['dialog' => $notificationPreferencesDialogId]);
        $invitationOpen = request()->query('dialog') === 'invite-member'
            || ($errors->getBag('default')->has('email') || old('email') !== null);
        $deleteWorkspaceOpen = $errors->getBag('deleteWorkspace')->any();
        $memberRoleDialogKey = request()->query('dialog');
        $memberRoleDialogMember = null;
        if ($canManage && is_string($memberRoleDialogKey) && preg_match('/\Amember-role-(\d+)\z/', $memberRoleDialogKey, $matches) === 1) {
            $memberRoleDialogMember = $organization->members->firstWhere('id', (int) $matches[1]);
            if ($memberRoleDialogMember?->id === $organization->owner_id) {
                $memberRoleDialogMember = null;
            }
        }
    @endphp

    <x-signal.ui.insights
        id="organization-insights"
        class="mt-6 scroll-mt-24"
        :summary="trans_choice(':count member|:count members', $organization->members->count(), ['count' => $organization->members->count()])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-signal.ui.stat
                :label="__('Members')"
                :value="$organization->members->count()"
                :description="__('People with current workspace access.')"
            />
            <x-signal.ui.stat
                :label="__('Pending invites')"
                :value="$invitations->count()"
                :description="__('Unaccepted invitations still available.')"
            />
            <x-signal.ui.stat
                :label="__('Seat usage')"
                :value="$memberUsage['plan_available'] && $memberUsage['limit_configured'] ? $memberUsage['used'].' / '.($memberUsage['limit'] ?? __('Unlimited')) : __('Unverified')"
                :description="$memberUsage['plan_available'] && $memberUsage['limit_configured'] ? __('Members and active invitations.') : __('Workspace plan details could not be confirmed.')"
            />
            <x-signal.ui.stat
                :label="__('Two-factor policy')"
                :value="$organization->require_two_factor ? __('Required') : __('Optional')"
                :description="__('Workspace-wide account security rule.')"
            />
            <x-signal.ui.stat
                :label="__('SSO policy')"
                :value="$organization->sso_enforced ? __('Required') : (filled($organization->sso_configuration['issuer'] ?? null) ? __('Configured') : __('Not configured'))"
                :description="__('OpenID Connect sign-in policy.')"
            />
        </dl>
    </x-signal.ui.insights>

    <x-signal.ui.local-nav class="mt-6" :label="__('Workspace sections')">
        <a href="#organization-members" class="ui-local-nav__link">{{ __('Members') }}</a>
        <a href="#organization-security-policy" class="ui-local-nav__link">{{ __('Security') }}</a>
        @if ($canManage)
            <a
                href="{{ $notificationPreferencesDialogUrl }}"
                class="ui-local-nav__link"
                data-modal-trigger="{{ $notificationPreferencesDialogId }}"
                aria-controls="{{ $notificationPreferencesDialogId }}"
                aria-expanded="{{ $notificationPreferencesDialogOpen ? 'true' : 'false' }}"
            >{{ __('Notifications') }}</a>
        @endif
        @if ($canManage)
            <a
                href="{{ route('organizations.index', ['dialog' => 'invite-member']) }}"
                class="ui-local-nav__link"
                data-modal-trigger="organization-invite"
                aria-controls="organization-invite"
                aria-expanded="{{ $invitationOpen ? 'true' : 'false' }}"
            >{{ __('Invitations') }}</a>
        @endif
        <a href="#organization-workspaces" class="ui-local-nav__link">{{ __('Workspaces') }}</a>
        <a href="#organization-delete" class="ui-local-nav__link">{{ __('Delete workspace') }}</a>
    </x-signal.ui.local-nav>

    <div class="mt-8 grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
        <section id="organization-members" class="scroll-mt-24">
        <x-forms.section
            :title="__('Members')"
            :description="__('People with access to :workspace.', ['workspace' => $organization->name])"
        >
            <div class="divide-y divide-line bg-surface">
                @foreach ($organization->members as $member)
                    <div class="flex flex-wrap items-center gap-4 px-4 py-4 sm:px-6">
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-ink">{{ $member->name }}</p>
                            <p class="truncate text-sm text-muted">{{ $member->email }}</p>
                        </div>

                        <x-signal.ui.badge :tone="$member->pivot->role === 'viewer' ? 'neutral' : 'accent'">
                            {{ ucfirst($member->pivot->role) }}
                        </x-signal.ui.badge>

                        @if ($canManage && $member->id !== $organization->owner_id)
                            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                                @php
                                    $memberRoleDialogId = 'organization-member-role-'.$member->id;
                                @endphp
                                <x-signal.ui.button
                                    :href="route('organizations.index', ['dialog' => 'member-role-'.$member->id])"
                                    data-modal-trigger="{{ $memberRoleDialogId }}"
                                    aria-controls="{{ $memberRoleDialogId }}"
                                    aria-expanded="{{ $memberRoleDialogMember?->id === $member->id ? 'true' : 'false' }}"
                                    variant="secondary"
                                >{{ __('Edit role') }}</x-signal.ui.button>
                                <form method="POST" action="{{ route('organizations.members.destroy', $member) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-signal.ui.button type="submit" variant="danger">{{ __('Remove') }}</x-signal.ui.button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-forms.section>
        </section>

        <div class="space-y-6">
            @if ($canManage)
                <x-forms.section
                    :title="__('Security policy')"
                    :description="__('Enforce access requirements for everyone in this workspace.')"
                    :collapsible="true"
                    id="organization-security-policy"
                    :open="$securityPolicyOpen"
                >
                    <form method="POST" action="{{ route('organizations.security-policy.update') }}" class="space-y-5 bg-surface p-5 sm:p-6">
                        @csrf
                        @method('PATCH')

                        <div>
                            <label for="allowed-ip-ranges" class="ui-label">{{ __('Allowed IP ranges') }}</label>
                            <x-signal.ui.textarea id="allowed-ip-ranges" name="allowed_ip_ranges" rows="3" class="ui-input mt-2 font-mono" placeholder="203.0.113.10/32&#10;2001:db8::/48" :restore="false">{{ old('allowed_ip_ranges', implode("\n", $organization->allowed_ip_ranges ?? [])) }}</x-signal.ui.textarea>
                            <p class="mt-1.5 text-xs text-muted">{{ __('Leave empty for any network. Your current IP must be included before saving.') }}</p>
                        </div>

                        <div>
                            <label for="allowed-email-domains" class="ui-label">{{ __('Member email domains') }}</label>
                            <x-signal.ui.input id="allowed-email-domains" name="allowed_email_domains" value="{{ old('allowed_email_domains', implode(', ', $organization->allowed_email_domains ?? [])) }}" class="ui-input mt-2" placeholder="example.com, agency.test" :restore="false" />
                        </div>

                        <x-signal.ui.input type="hidden" name="require_two_factor" value="0" :restore="false" />
                        <label class="flex items-start gap-3 text-sm text-muted">
                            <x-signal.ui.input type="checkbox" name="require_two_factor" value="1" class="ui-check mt-1" @checked(old('require_two_factor', $organization->require_two_factor)) :restore="false" />
                            <span><strong class="block text-ink">{{ __('Require two-factor authentication') }}</strong>{{ __('Members without 2FA are limited to their account security screen.') }}</span>
                        </label>

                        <div>
                            <label for="session-idle-minutes" class="ui-label">{{ __('Idle session timeout') }}</label>
                            <x-signal.ui.select id="session-idle-minutes" name="session_idle_minutes" class="ui-input mt-2">
                                <option value="">{{ __('Use platform default') }}</option>
                                @foreach ([15, 30, 60, 240, 720, 1440] as $minutes)
                                    <option value="{{ $minutes }}" @selected((int) old('session_idle_minutes', $organization->session_idle_minutes) === $minutes)>
                                        {{ $minutes < 60 ? $minutes.' minutes' : ($minutes / 60).' hours' }}
                                    </option>
                                @endforeach
                            </x-signal.ui.select>
                        </div>

                        <div class="border-t border-line pt-5">
                            <p class="font-bold text-ink">{{ __('OpenID Connect SSO') }}</p>
                            <p class="mt-1 text-xs leading-5 text-muted">{{ __('Use the callback URL :url in your identity provider.', ['url' => route('organizations.sso.callback')]) }}</p>
                        </div>

                        <div>
                            <label for="sso-issuer" class="ui-label">{{ __('Issuer URL') }}</label>
                            <x-signal.ui.input id="sso-issuer" type="url" name="sso_issuer" value="{{ old('sso_issuer', $organization->sso_configuration['issuer'] ?? '') }}" class="ui-input mt-2" placeholder="https://identity.example.com" :restore="false" />
                        </div>

                        <div>
                            <label for="sso-client-id" class="ui-label">{{ __('Client ID') }}</label>
                            <x-signal.ui.input id="sso-client-id" name="sso_client_id" value="{{ old('sso_client_id', $organization->sso_configuration['client_id'] ?? '') }}" class="ui-input mt-2" :restore="false" />
                        </div>

                        <div>
                            <label for="sso-client-secret" class="ui-label">{{ __('Client secret') }}</label>
                            <x-signal.ui.input id="sso-client-secret" type="password" name="sso_client_secret" class="ui-input mt-2" autocomplete="new-password" placeholder="{{ filled($organization->sso_configuration['client_secret'] ?? null) ? __('Stored — leave blank to keep') : '' }}" :restore="false" />
                        </div>

                        <x-signal.ui.input type="hidden" name="sso_enforced" value="0" :restore="false" />
                        <label class="flex items-start gap-3 text-sm text-muted">
                            <x-signal.ui.input type="checkbox" name="sso_enforced" value="1" class="ui-check mt-1" @checked(old('sso_enforced', $organization->sso_enforced)) :restore="false" />
                            <span><strong class="block text-ink">{{ __('Require workspace SSO') }}</strong>{{ __('Members must re-verify through your identity provider in each session.') }}</span>
                        </label>

                        <div class="flex flex-wrap gap-2">
                            @if (filled($organization->sso_configuration['issuer'] ?? null))
                                <x-signal.ui.button href="{{ route('organizations.sso.connect') }}" variant="secondary">{{ __('Test SSO') }}</x-signal.ui.button>
                            @endif
                            <x-signal.ui.button type="submit" variant="primary">{{ __('Save security policy') }}</x-signal.ui.button>
                        </div>
                    </form>
                </x-forms.section>

                <x-forms.section
                    :title="__('Notification preferences')"
                    :description="__('Choose which events create inbox notifications for this workspace. Alert destinations are configured separately in Observability.')"
                    :collapsible="true"
                    id="organization-notification-preferences"
                    :open="$notificationPreferencesOpen"
                >
                    @php($enabledNotificationCategories = $organization->notification_preferences['categories'] ?? ['website', 'server', 'deployment', 'provider', 'security', 'recipe'])
                    <div class="flex flex-wrap items-start justify-between gap-4 bg-surface p-5 sm:p-6">
                        <div>
                            <p class="font-semibold text-ink">
                                {{ trans_choice(':count inbox category enabled|:count inbox categories enabled', count($enabledNotificationCategories), ['count' => count($enabledNotificationCategories)]) }}
                            </p>
                            <p class="mt-1 text-sm text-muted">
                                {{ ($organization->notification_preferences['recoveries'] ?? true) ? __('Recovery alerts are enabled.') : __('Recovery alerts are disabled.') }}
                            </p>
                        </div>
                        <x-signal.ui.button
                            href="{{ $notificationPreferencesDialogUrl }}"
                            data-modal-trigger="{{ $notificationPreferencesDialogId }}"
                            aria-controls="{{ $notificationPreferencesDialogId }}"
                            aria-expanded="{{ $notificationPreferencesDialogOpen ? 'true' : 'false' }}"
                            variant="secondary"
                        >{{ __('Edit preferences') }}</x-signal.ui.button>
                    </div>
                </x-forms.section>
            @endif

            <x-forms.section
                :title="__('Your workspaces')"
                :description="__('Switch the active workspace.')"
                :collapsible="true"
                id="organization-workspaces"
            >
                <div class="space-y-2 bg-surface p-5 sm:p-6">
                    @foreach (auth()->user()->organizations as $workspace)
                        <form method="POST" action="{{ route('organizations.switch', $workspace) }}">
                            @csrf
                            <x-signal.ui.button type="submit" class="w-full justify-between" :variant="$workspace->id === $organization->id ? 'primary' : 'secondary'">
                                <span>{{ $workspace->name }}</span>
                                <span class="text-xs opacity-75">{{ ucfirst($workspace->pivot->role) }}</span>
                            </x-signal.ui.button>
                        </form>
                    @endforeach
                </div>
            </x-forms.section>
        </div>
    </div>

    @if ($canManage)
        <x-scenes.organizations.invite-dialog :member-usage="$memberUsage" :open="$invitationOpen" />
        <x-scenes.organizations.notification-preferences-dialog
            :open="$notificationPreferencesDialogOpen"
            :organization="$organization"
        />
        @foreach ($organization->members as $member)
            @if ($member->id !== $organization->owner_id)
                <x-scenes.organizations.member-role-dialog
                    :action="route('organizations.members.update', $member)"
                    :id="'organization-member-role-'.$member->id"
                    :member="$member"
                    :open="$memberRoleDialogMember?->id === $member->id"
                />
            @endif
        @endforeach
    @endif

    @if ($organization->owner->is(auth()->user()))
        <x-signal.ui.panel as="details" id="organization-delete" class="ui-responsive-details group ui-panel ui-panel--danger mt-8" open data-responsive-details data-responsive-details-mobile-open="{{ $deleteWorkspaceOpen ? 'true' : 'false' }}">
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-5 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus sm:p-6 lg:hidden">
                <span>
                    <span class="block text-xl font-extrabold text-ink">{{ __('Delete workspace') }}</span>
                    <span class="mt-2 block text-sm leading-6 text-muted">{{ __('Permanently removes this workspace and its :app records.', ['app' => config('app.name')]) }}</span>
                </span>
                <span class="shrink-0 text-xl transition-transform group-open:rotate-45" style="color: var(--ui-danger)" aria-hidden="true">+</span>
            </summary>
            <div class="ui-responsive-details__content p-5 sm:p-6 lg:block">
                <div class="max-w-3xl">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-6 w-6 shrink-0" style="color: var(--ui-danger)" aria-hidden="true">
                        <use xlink:href="/assets/images/icons.svg#exclamation"></use>
                    </svg>
                    <div>
                        <h2 id="delete-workspace-title" class="text-xl font-extrabold text-ink">{{ __('Delete workspace') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ __('Permanently removes this workspace and its :app records. Provider-side servers and resources remain in your connected accounts. Remove teammates and finish active operations first.', ['app' => config('app.name')]) }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('organizations.destroy', $organization) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('DELETE')
                    <div>
                        <label for="workspace-confirmation" class="ui-label">{{ __('Type “:name”', ['name' => $organization->name]) }}</label>
                        <x-signal.ui.input id="workspace-confirmation" name="confirmation" class="ui-input mt-2" required :restore="false" />
                    </div>
                    @if (auth()->user()->hasLocalPassword())
                        <div>
                            <label for="workspace-current-password" class="ui-label">{{ __('Current password') }}</label>
                            <x-signal.ui.input id="workspace-current-password" type="password" name="current_password" autocomplete="current-password" class="ui-input mt-2" required :restore="false" />
                        </div>
                    @endif
                    @if (auth()->user()->twoFactorEnabled())
                        <div>
                            <label for="workspace-two-factor-code" class="ui-label">{{ __('Authenticator or recovery code') }}</label>
                            <x-signal.ui.input id="workspace-two-factor-code" name="code" autocomplete="one-time-code" class="ui-input mt-2 font-mono" required :restore="false" />
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <x-signal.ui.button type="submit" variant="danger" onclick="return confirm({{ Illuminate\Support\Js::from(__('Permanently delete this workspace?')) }})">{{ __('Permanently delete workspace') }}</x-signal.ui.button>
                    </div>
                </form>
            </div>
            </div>
        </x-signal.ui.panel>
    @endif
</x-layouts.app>
