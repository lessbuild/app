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
            <input id="invitation-email" required type="email" name="email" value="{{ old('email') }}" class="ui-input mt-2 w-full" autocomplete="email">
        </div>
        <div>
            <label for="invitation-role" class="ui-label">{{ __('Role') }}</label>
            <select id="invitation-role" name="role" class="ui-input mt-2 w-full">
                @foreach (\App\Modules\Deployer\Models\Organization::ROLES as $role)
                    <option value="{{ $role }}" @selected($role === old('role', \App\Modules\Deployer\Models\Organization::ROLES[0]))>{{ ucfirst($role) }}</option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="email" />
        <x-forms.errors name="role" />
        @if (! $memberUsage['plan_available'] || ! $memberUsage['limit_configured'])
            <x-ui.alert tone="warning">
                {{ __('We could not confirm this workspace’s Deployer plan and seat allowance. Retry shortly or contact support.') }}
            </x-ui.alert>
        @elseif (! $memberUsage['allowed'])
            <x-ui.alert tone="warning">
                {{ __('Your plan’s member limit has been reached.') }} <a href="{{ route('billing.index') }}" class="ui-link">{{ __('Upgrade') }}</a>
            </x-ui.alert>
        @endif
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="submit" variant="primary" :disabled="! $memberUsage['allowed']">{{ __('Send invitation') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
