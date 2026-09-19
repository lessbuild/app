<x-layouts.app>
    <x-layouts.partials.heading
        eyebrow="{{ __('Workspace administration') }}"
        icon="user-circle"
        :title="__('Workspace')"
        :description="__('Manage members, roles, and workspace access.')"
    >
        @if ($canManage)
            <x-slot:buttons>
                <x-ui.button
                    href="{{ route('organizations.index', ['dialog' => 'invite-member']) }}"
                    data-modal-trigger="organization-invite"
                    aria-controls="organization-invite"
                    aria-expanded="{{ request()->query('dialog') === 'invite-member' ? 'true' : 'false' }}"
                    variant="primary"
                >
                    {{ __('Invite member') }}
                </x-ui.button>
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

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
        $invitationOpen = request()->query('dialog') === 'invite-member'
            || ($errors->getBag('default')->has('email') || old('email') !== null);
        $deleteWorkspaceOpen = $errors->getBag('deleteWorkspace')->any();
    @endphp

    <x-ui.insights
        id="organization-insights"
        class="mt-6"
        :summary="trans_choice(':count member|:count members', $organization->members->count(), ['count' => $organization->members->count()])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.stat
                :label="__('Members')"
                :value="$organization->members->count()"
                :description="__('People with current workspace access.')"
            />
            <x-ui.stat
                :label="__('Pending invites')"
                :value="$invitations->count()"
                :description="__('Unaccepted invitations still available.')"
            />
            <x-ui.stat
                :label="__('Seat usage')"
                :value="$memberUsage['used'].' / '.($memberUsage['limit'] ?? __('Unlimited'))"
                :description="__('Members and active invitations.')"
            />
            <x-ui.stat
                :label="__('Two-factor policy')"
                :value="$organization->require_two_factor ? __('Required') : __('Optional')"
                :description="__('Workspace-wide account security rule.')"
            />
            <x-ui.stat
                :label="__('SSO policy')"
                :value="$organization->sso_enforced ? __('Required') : (filled($organization->sso_configuration['issuer'] ?? null) ? __('Configured') : __('Not configured'))"
                :description="__('OpenID Connect sign-in policy.')"
            />
        </dl>
    </x-ui.insights>

    <x-ui.local-nav :label="__('Workspace sections')">
        <a href="#organization-security-policy" class="ui-local-nav__link">{{ __('Security') }}</a>
        <a href="#organization-notification-preferences" class="ui-local-nav__link">{{ __('Notifications') }}</a>
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
    </x-ui.local-nav>

    <div class="mt-8 grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
        <x-forms.section
            :title="__('Members')"
            :description="__('People with access to :workspace.', ['workspace' => $organization->name])"
        >
            <div class="divide-y divide-primary bg-primary">
                @foreach ($organization->members as $member)
                    <div class="flex flex-wrap items-center gap-4 px-4 py-4 sm:px-6">
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-primary">{{ $member->name }}</p>
                            <p class="truncate text-sm text-secondary">{{ $member->email }}</p>
                        </div>

                        <x-ui.badge :tone="$member->pivot->role === 'viewer' ? 'neutral' : 'accent'">
                            {{ ucfirst($member->pivot->role) }}
                        </x-ui.badge>

                        @if ($canManage && $member->id !== $organization->owner_id)
                            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                                <form method="POST" action="{{ route('organizations.members.update', $member) }}" class="flex min-w-56 flex-1 gap-2 sm:flex-none">
                                    @csrf
                                    @method('PATCH')
                                    <label class="sr-only" for="role-{{ $member->id }}">{{ __('Role for :name', ['name' => $member->name]) }}</label>
                                    <select id="role-{{ $member->id }}" name="role" class="input secondary min-w-0 flex-1 rounded-lg">
                                        @foreach (\App\Models\Organization::ROLES as $role)
                                            <option value="{{ $role }}" @selected($member->pivot->role === $role)>{{ ucfirst($role) }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button type="submit" variant="primary">{{ __('Save') }}</x-ui.button>
                                </form>
                                <form method="POST" action="{{ route('organizations.members.destroy', $member) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger">{{ __('Remove') }}</x-ui.button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-forms.section>

        <div class="space-y-6">
            @if ($canManage)
                <x-forms.section
                    :title="__('Security policy')"
                    :description="__('Enforce access requirements for everyone in this workspace.')"
                    :collapsible="true"
                    id="organization-security-policy"
                    :open="$securityPolicyOpen"
                >
                    <form method="POST" action="{{ route('organizations.security-policy.update') }}" class="space-y-5 bg-primary p-5 sm:p-6">
                        @csrf
                        @method('PATCH')

                        <div>
                            <label for="allowed-ip-ranges" class="block text-sm font-bold text-primary">{{ __('Allowed IP ranges') }}</label>
                            <textarea id="allowed-ip-ranges" name="allowed_ip_ranges" rows="3" class="input secondary mt-2 w-full rounded-lg font-mono" placeholder="203.0.113.10/32&#10;2001:db8::/48">{{ old('allowed_ip_ranges', implode("\n", $organization->allowed_ip_ranges ?? [])) }}</textarea>
                            <p class="mt-1.5 text-xs text-secondary">{{ __('Leave empty for any network. Your current IP must be included before saving.') }}</p>
                        </div>

                        <div>
                            <label for="allowed-email-domains" class="block text-sm font-bold text-primary">{{ __('Member email domains') }}</label>
                            <input id="allowed-email-domains" name="allowed_email_domains" value="{{ old('allowed_email_domains', implode(', ', $organization->allowed_email_domains ?? [])) }}" class="input secondary mt-2 w-full rounded-lg" placeholder="example.com, agency.test">
                        </div>

                        <input type="hidden" name="require_two_factor" value="0">
                        <label class="flex items-start gap-3 text-sm text-secondary">
                            <input type="checkbox" name="require_two_factor" value="1" @checked(old('require_two_factor', $organization->require_two_factor))>
                            <span><strong class="block text-primary">{{ __('Require two-factor authentication') }}</strong>{{ __('Members without 2FA are limited to their account security screen.') }}</span>
                        </label>

                        <div>
                            <label for="session-idle-minutes" class="block text-sm font-bold text-primary">{{ __('Idle session timeout') }}</label>
                            <select id="session-idle-minutes" name="session_idle_minutes" class="input secondary mt-2 w-full rounded-lg">
                                <option value="">{{ __('Use platform default') }}</option>
                                @foreach ([15, 30, 60, 240, 720, 1440] as $minutes)
                                    <option value="{{ $minutes }}" @selected((int) old('session_idle_minutes', $organization->session_idle_minutes) === $minutes)>
                                        {{ $minutes < 60 ? $minutes.' minutes' : ($minutes / 60).' hours' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="border-t border-primary pt-5">
                            <p class="font-bold text-primary">{{ __('OpenID Connect SSO') }}</p>
                            <p class="mt-1 text-xs leading-5 text-secondary">{{ __('Use the callback URL :url in your identity provider.', ['url' => route('organizations.sso.callback')]) }}</p>
                        </div>

                        <div>
                            <label for="sso-issuer" class="block text-sm font-bold text-primary">{{ __('Issuer URL') }}</label>
                            <input id="sso-issuer" type="url" name="sso_issuer" value="{{ old('sso_issuer', $organization->sso_configuration['issuer'] ?? '') }}" class="input secondary mt-2 w-full rounded-lg" placeholder="https://identity.example.com">
                        </div>

                        <div>
                            <label for="sso-client-id" class="block text-sm font-bold text-primary">{{ __('Client ID') }}</label>
                            <input id="sso-client-id" name="sso_client_id" value="{{ old('sso_client_id', $organization->sso_configuration['client_id'] ?? '') }}" class="input secondary mt-2 w-full rounded-lg">
                        </div>

                        <div>
                            <label for="sso-client-secret" class="block text-sm font-bold text-primary">{{ __('Client secret') }}</label>
                            <input id="sso-client-secret" type="password" name="sso_client_secret" class="input secondary mt-2 w-full rounded-lg" autocomplete="new-password" placeholder="{{ filled($organization->sso_configuration['client_secret'] ?? null) ? __('Stored — leave blank to keep') : '' }}">
                        </div>

                        <input type="hidden" name="sso_enforced" value="0">
                        <label class="flex items-start gap-3 text-sm text-secondary">
                            <input type="checkbox" name="sso_enforced" value="1" @checked(old('sso_enforced', $organization->sso_enforced))>
                            <span><strong class="block text-primary">{{ __('Require workspace SSO') }}</strong>{{ __('Members must re-verify through your identity provider in each session.') }}</span>
                        </label>

                        <div class="flex flex-wrap gap-2">
                            @if (filled($organization->sso_configuration['issuer'] ?? null))
                                <x-ui.button href="{{ route('organizations.sso.connect') }}" variant="secondary">{{ __('Test SSO') }}</x-ui.button>
                            @endif
                            <x-ui.button type="submit" variant="primary">{{ __('Save security policy') }}</x-ui.button>
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
                    <form method="POST" action="{{ route('organizations.notification-preferences.update') }}" class="space-y-5 bg-primary p-5 sm:p-6">
                        @csrf
                        @method('PATCH')
                        @php
                            $enabledCategories = $organization->notification_preferences['categories'] ?? ['website', 'server', 'deployment', 'provider', 'security', 'recipe'];
                        @endphp

                        <fieldset>
                            <legend class="text-sm font-bold text-primary">{{ __('Inbox categories') }}</legend>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach (['website' => __('Websites'), 'server' => __('Servers'), 'deployment' => __('Deployments'), 'provider' => __('Providers'), 'security' => __('Security'), 'recipe' => __('Recipes')] as $value => $label)
                                    <label class="flex items-center gap-3 text-sm text-secondary">
                                        <input type="checkbox" name="categories[]" value="{{ $value }}" @checked(in_array($value, old('categories', $enabledCategories), true))>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <input type="hidden" name="recoveries" value="0">
                        <label class="flex items-start gap-3 text-sm text-secondary">
                            <input type="checkbox" name="recoveries" value="1" @checked(old('recoveries', $organization->notification_preferences['recoveries'] ?? true))>
                            <span><strong class="block text-primary">{{ __('Recovery notifications') }}</strong>{{ __('Notify when a failed resource becomes healthy again.') }}</span>
                        </label>

                        <x-ui.button type="submit" variant="primary">{{ __('Save preferences') }}</x-ui.button>
                    </form>
                </x-forms.section>
            @endif

            <x-forms.section
                :title="__('Your workspaces')"
                :description="__('Switch the active workspace.')"
                :collapsible="true"
                id="organization-workspaces"
            >
                <div class="space-y-2 bg-primary p-5 sm:p-6">
                    @foreach (auth()->user()->organizations as $workspace)
                        <form method="POST" action="{{ route('organizations.switch', $workspace) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full justify-between" :variant="$workspace->id === $organization->id ? 'primary' : 'secondary'">
                                <span>{{ $workspace->name }}</span>
                                <span class="text-xs opacity-75">{{ ucfirst($workspace->pivot->role) }}</span>
                            </x-ui.button>
                        </form>
                    @endforeach
                </div>
            </x-forms.section>
        </div>
    </div>

    @if ($canManage)
        <x-scenes.organizations.invite-dialog :member-usage="$memberUsage" :open="$invitationOpen" />
    @endif

    @if ($organization->owner->is(auth()->user()))
        <details id="organization-delete" class="ui-responsive-details group ui-card mt-8 border-red-200 bg-red-50" open data-responsive-details data-responsive-details-mobile-open="{{ $deleteWorkspaceOpen ? 'true' : 'false' }}">
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-5 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 sm:p-6 lg:hidden">
                <span>
                    <span class="block text-xl font-black text-red-900">{{ __('Delete workspace') }}</span>
                    <span class="mt-2 block text-sm leading-6 text-red-800">{{ __('Permanently removes this workspace and its BuildPusher records.') }}</span>
                </span>
                <span class="shrink-0 text-xl text-red-700 transition-transform group-open:rotate-45" aria-hidden="true">+</span>
            </summary>
            <div class="ui-responsive-details__content p-5 sm:p-6 lg:block">
                <div class="max-w-3xl">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-6 w-6 shrink-0 text-red-700" aria-hidden="true">
                        <use xlink:href="/assets/images/icons.svg#exclamation"></use>
                    </svg>
                    <div>
                        <h2 id="delete-workspace-title" class="text-xl font-black text-red-900">{{ __('Delete workspace') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-red-800">{{ __('Permanently removes this workspace and its BuildPusher records. Provider-side servers and resources remain in your connected accounts. Remove teammates and finish active operations first.') }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('organizations.destroy', $organization) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('DELETE')
                    <div>
                        <label for="workspace-confirmation" class="block text-sm font-bold text-red-900">{{ __('Type “:name”', ['name' => $organization->name]) }}</label>
                        <input id="workspace-confirmation" name="confirmation" class="input secondary mt-2 w-full rounded-lg" required>
                    </div>
                    @if (auth()->user()->hasLocalPassword())
                        <div>
                            <label for="workspace-current-password" class="block text-sm font-bold text-red-900">{{ __('Current password') }}</label>
                            <input id="workspace-current-password" type="password" name="current_password" autocomplete="current-password" class="input secondary mt-2 w-full rounded-lg" required>
                        </div>
                    @endif
                    @if (auth()->user()->twoFactorEnabled())
                        <div>
                            <label for="workspace-two-factor-code" class="block text-sm font-bold text-red-900">{{ __('Authenticator or recovery code') }}</label>
                            <input id="workspace-two-factor-code" name="code" autocomplete="one-time-code" class="input secondary mt-2 w-full rounded-lg font-mono" required>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <x-ui.button type="submit" variant="danger" onclick="return confirm({{ Illuminate\Support\Js::from(__('Permanently delete this workspace?')) }})">{{ __('Permanently delete workspace') }}</x-ui.button>
                    </div>
                </form>
            </div>
            </div>
        </details>
    @endif
</x-layouts.app>
