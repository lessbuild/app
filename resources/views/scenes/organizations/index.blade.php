<x-layouts.app>
    <x-layouts.partials.heading icon="user-circle" :title="__('Workspace')" :description="__('Manage members, roles, and workspace access.')" />
    <div class="mt-8 grid gap-6 lg:grid-cols-[1.25fr_.75fr]">
        <x-forms.section :title="__('Members')" :description="__('People with access to :workspace.', ['workspace' => $organization->name])">
            <div class="divide-y divide-primary bg-primary">
                @foreach($organization->members as $member)
                    <div class="flex flex-wrap items-center gap-3 px-4 py-4 sm:px-6"><div class="min-w-0 flex-1"><p class="font-bold text-primary">{{ $member->name }}</p><p class="truncate text-sm text-secondary">{{ $member->email }}</p></div><span class="rounded-full bg-secondary px-3 py-1 text-xs font-bold uppercase text-secondary">{{ $member->pivot->role }}</span>
                    @if($canManage && $member->id !== $organization->owner_id)<form method="POST" action="{{ route('organizations.members.update', $member) }}" class="flex gap-2">@csrf @method('PATCH')<select name="role" class="input secondary rounded">@foreach(\App\Models\Organization::ROLES as $role)<option value="{{ $role }}" @selected($member->pivot->role === $role)>{{ ucfirst($role) }}</option>@endforeach</select><button type="submit" class="button primary">{{ __('Save') }}</button></form><form method="POST" action="{{ route('organizations.members.destroy', $member) }}">@csrf @method('DELETE')<button type="submit" class="button tertiary">{{ __('Remove') }}</button></form>@endif</div>
                @endforeach
            </div>
        </x-forms.section>
        <div class="space-y-6">
            @if($canManage)
                <x-forms.section :title="__('Security policy')" :description="__('Enforce access requirements for everyone in this workspace.')">
                    <form method="POST" action="{{ route('organizations.security-policy.update') }}" class="space-y-4 bg-primary p-5">@csrf @method('PATCH')
                        <label class="block"><span class="mb-1 block text-sm font-bold text-primary">{{ __('Allowed IP ranges') }}</span><textarea name="allowed_ip_ranges" rows="3" class="input secondary w-full rounded font-mono" placeholder="203.0.113.10/32&#10;2001:db8::/48">{{ old('allowed_ip_ranges', implode("\n", $organization->allowed_ip_ranges ?? [])) }}</textarea><span class="mt-1 block text-xs text-secondary">{{ __('Leave empty for any network. Your current IP must be included before saving.') }}</span></label>
                        <label class="block"><span class="mb-1 block text-sm font-bold text-primary">{{ __('Member email domains') }}</span><input name="allowed_email_domains" value="{{ old('allowed_email_domains', implode(', ', $organization->allowed_email_domains ?? [])) }}" class="input secondary w-full rounded" placeholder="example.com, agency.test"></label>
                        <input type="hidden" name="require_two_factor" value="0"><label class="flex items-start gap-2 text-sm text-secondary"><input type="checkbox" name="require_two_factor" value="1" @checked(old('require_two_factor', $organization->require_two_factor))><span><strong class="block text-primary">{{ __('Require two-factor authentication') }}</strong>{{ __('Members without 2FA are limited to their account security screen.') }}</span></label>
                        <label class="block"><span class="mb-1 block text-sm font-bold text-primary">{{ __('Idle session timeout') }}</span><select name="session_idle_minutes" class="input secondary w-full rounded"><option value="">{{ __('Use platform default') }}</option>@foreach([15,30,60,240,720,1440] as $minutes)<option value="{{ $minutes }}" @selected((int) old('session_idle_minutes', $organization->session_idle_minutes) === $minutes)>{{ $minutes < 60 ? $minutes.' minutes' : ($minutes / 60).' hours' }}</option>@endforeach</select></label>
                        <div class="border-t border-primary pt-4"><p class="font-bold text-primary">{{ __('OpenID Connect SSO') }}</p><p class="mt-1 text-xs text-secondary">{{ __('Use the callback URL :url in your identity provider.', ['url' => route('organizations.sso.callback')]) }}</p></div>
                        <label class="block"><span class="mb-1 block text-sm text-secondary">{{ __('Issuer URL') }}</span><input type="url" name="sso_issuer" value="{{ old('sso_issuer', $organization->sso_configuration['issuer'] ?? '') }}" class="input secondary w-full rounded" placeholder="https://identity.example.com"></label>
                        <label class="block"><span class="mb-1 block text-sm text-secondary">{{ __('Client ID') }}</span><input name="sso_client_id" value="{{ old('sso_client_id', $organization->sso_configuration['client_id'] ?? '') }}" class="input secondary w-full rounded"></label>
                        <label class="block"><span class="mb-1 block text-sm text-secondary">{{ __('Client secret') }}</span><input type="password" name="sso_client_secret" class="input secondary w-full rounded" autocomplete="new-password" placeholder="{{ filled($organization->sso_configuration['client_secret'] ?? null) ? __('Stored — leave blank to keep') : '' }}"></label>
                        <input type="hidden" name="sso_enforced" value="0"><label class="flex items-start gap-2 text-sm text-secondary"><input type="checkbox" name="sso_enforced" value="1" @checked(old('sso_enforced', $organization->sso_enforced))><span><strong class="block text-primary">{{ __('Require workspace SSO') }}</strong>{{ __('Members must re-verify through your identity provider in each session.') }}</span></label>
                        @if(filled($organization->sso_configuration['issuer'] ?? null))<a href="{{ route('organizations.sso.connect') }}" class="button secondary">{{ __('Test SSO') }}</a>@endif
                        <button type="submit" class="button primary">{{ __('Save security policy') }}</button>
                    </form>
                </x-forms.section>
                <x-forms.section :title="__('Notification preferences')" :description="__('Choose which events create inbox notifications for this workspace. Alert destinations are configured separately in Observability.')">
                    <form method="POST" action="{{ route('organizations.notification-preferences.update') }}" class="space-y-4 bg-primary p-5">
                        @csrf @method('PATCH')
                        @php($enabledCategories = $organization->notification_preferences['categories'] ?? ['website', 'server', 'deployment', 'provider', 'security', 'recipe'])
                        <fieldset>
                            <legend class="text-sm font-bold text-primary">{{ __('Inbox categories') }}</legend>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach(['website' => __('Websites'), 'server' => __('Servers'), 'deployment' => __('Deployments'), 'provider' => __('Providers'), 'security' => __('Security'), 'recipe' => __('Recipes')] as $value => $label)
                                    <label class="flex items-center gap-2 text-sm text-secondary"><input type="checkbox" name="categories[]" value="{{ $value }}" @checked(in_array($value, old('categories', $enabledCategories), true))><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>
                        <input type="hidden" name="recoveries" value="0">
                        <label class="flex items-start gap-2 text-sm text-secondary"><input type="checkbox" name="recoveries" value="1" @checked(old('recoveries', $organization->notification_preferences['recoveries'] ?? true))><span><strong class="block text-primary">{{ __('Recovery notifications') }}</strong>{{ __('Notify when a failed resource becomes healthy again.') }}</span></label>
                        <button type="submit" class="button primary">{{ __('Save preferences') }}</button>
                    </form>
                </x-forms.section>
            @endif
            @if($canManage)<x-forms.section :title="__('Invite member')" :description="__('Invitations expire after seven days.')"><form method="POST" action="{{ route('organizations.invitations.store') }}" class="space-y-4 bg-primary p-5">@csrf<label class="block"><span class="mb-1 block text-sm text-secondary">{{ __('Email') }}</span><input required type="email" name="email" class="input secondary rounded"></label><label class="block"><span class="mb-1 block text-sm text-secondary">{{ __('Role') }}</span><select name="role" class="input secondary rounded">@foreach(\App\Models\Organization::ROLES as $role)<option value="{{ $role }}">{{ ucfirst($role) }}</option>@endforeach</select></label><button type="submit" class="button primary" @disabled(!$memberUsage['allowed'])>{{ __('Send invitation') }}</button>@unless($memberUsage['allowed'])<p class="text-sm text-secondary">{{ __('Your plan’s member limit has been reached.') }} <a href="{{ route('billing.index') }}" class="font-bold underline">{{ __('Upgrade') }}</a></p>@endunless</form></x-forms.section>@endif
            <x-forms.section :title="__('Your workspaces')" :description="__('Switch the active workspace.')"><div class="space-y-2 bg-primary p-5">@foreach(auth()->user()->organizations as $workspace)<form method="POST" action="{{ route('organizations.switch', $workspace) }}">@csrf<button type="submit" class="button {{ $workspace->id === $organization->id ? 'primary' : 'tertiary' }} w-full justify-between"><span>{{ $workspace->name }}</span><span>{{ ucfirst($workspace->pivot->role) }}</span></button></form>@endforeach</div></x-forms.section>
        </div>
    </div>
    @if($organization->owner->is(auth()->user()))
        <section class="mt-8 rounded-2xl border border-red-200 bg-red-50 p-5">
            <h2 class="text-xl font-black text-red-900">{{ __('Delete workspace') }}</h2>
            <p class="mt-2 text-sm leading-6 text-red-800">{{ __('Permanently removes this workspace and its BuildPusher records. Provider-side servers and resources remain in your connected accounts. Remove teammates and finish active operations first.') }}</p>
            <form method="POST" action="{{ route('organizations.destroy', $organization) }}" class="mt-5 grid gap-3 sm:grid-cols-2">
                @csrf @method('DELETE')
                <label class="block"><span class="mb-1 block text-sm text-red-900">{{ __('Type “:name”', ['name' => $organization->name]) }}</span><input name="confirmation" class="input secondary w-full rounded" required></label>
                @if(auth()->user()->hasLocalPassword())<label class="block"><span class="mb-1 block text-sm text-red-900">{{ __('Current password') }}</span><input type="password" name="current_password" autocomplete="current-password" class="input secondary w-full rounded" required></label>@endif
                @if(auth()->user()->twoFactorEnabled())<label class="block"><span class="mb-1 block text-sm text-red-900">{{ __('Authenticator or recovery code') }}</span><input name="code" autocomplete="one-time-code" class="input secondary w-full rounded font-mono" required></label>@endif
                <div class="sm:col-span-2"><button type="submit" class="button secondary text-red-700" onclick="return confirm({{ Illuminate\Support\Js::from(__('Permanently delete this workspace?')) }})">{{ __('Permanently delete workspace') }}</button></div>
            </form>
        </section>
    @endif
</x-layouts.app>
