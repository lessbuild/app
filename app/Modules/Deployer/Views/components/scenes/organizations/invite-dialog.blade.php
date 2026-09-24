@props([
    'memberUsage',
    'open' => false,
])

<x-dialogs.modal
    id="organization-invite"
    :title="__('Invite member')"
    :description="__('Invitations expire after seven days.')"
    :open="$open"
>
    <form method="POST" action="{{ route('organizations.invitations.store') }}" class="space-y-5">
        @csrf
        <div>
            <label for="invitation-email" class="ui-label">{{ __('Email') }}</label>
            <x-signal.ui.input id="invitation-email" required type="email" name="email" value="{{ old('email') }}" class="ui-input mt-2 w-full" autocomplete="email" :restore="false" />
        </div>
        <div>
            <label for="invitation-role" class="ui-label">{{ __('Role') }}</label>
            <x-signal.ui.select id="invitation-role" name="role" class="ui-input mt-2 w-full">
                @foreach (\App\Modules\Deployer\Models\Organization::ROLES as $role)
                    <option value="{{ $role }}" @selected($role === old('role', \App\Modules\Deployer\Models\Organization::ROLES[0]))>{{ ucfirst($role) }}</option>
                @endforeach
            </x-signal.ui.select>
        </div>
        <x-forms.errors name="email" />
        <x-forms.errors name="role" />
        @if (! $memberUsage['plan_available'] || ! $memberUsage['limit_configured'])
            <x-signal.ui.alert tone="warning">
                {{ __('We could not confirm this workspace’s Deployer plan and seat allowance. Retry shortly or contact support.') }}
            </x-signal.ui.alert>
        @elseif (! $memberUsage['allowed'])
            <x-signal.ui.alert tone="warning">
                {{ __('Your plan’s member limit has been reached.') }} <a href="{{ route('billing.index') }}" class="ui-link">{{ __('Upgrade') }}</a>
            </x-signal.ui.alert>
        @endif
        <div class="flex flex-wrap items-center gap-3">
            <x-signal.ui.button type="submit" variant="primary" :disabled="! $memberUsage['allowed']">{{ __('Send invitation') }}</x-signal.ui.button>
        </div>
    </form>
</x-dialogs.modal>
