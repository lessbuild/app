<x-signal.layouts.admin :title="__('Customers')" :description="__('Find a person by email, name or ID, or an account by name, ID or Stripe customer ID. Opening one is recorded in the admin trail.')">
    <form method="GET" action="{{ route('admin.customers') }}" class="flex flex-wrap items-end gap-3">
        <x-signal.ui.input-field name="q" :label="__('Search')" :value="$term" maxlength="200" :restore="false" autofocus />
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Search') }}</x-signal.ui.button>
    </form>

    @if (mb_strlen(trim($term)) >= 2)
        <div class="grid gap-4 lg:grid-cols-2">
            <x-signal.ui.table :caption="__('People')">
                <x-slot:head><tr><th scope="col">{{ __('Person') }}</th><th scope="col">{{ __('Joined') }}</th></tr></x-slot:head>
                @forelse ($users as $user)
                    <tr><td><a href="{{ route('admin.customers.users', $user->id) }}" class="font-bold text-primary hover:underline">{{ $user->email }}</a> <span class="text-muted">{{ $user->name }}</span></td><td>{{ $user->created_at?->toFormattedDateString() }}</td></tr>
                @empty
                    <tr><td colspan="2" class="text-muted">{{ __('No one matches.') }}</td></tr>
                @endforelse
            </x-signal.ui.table>
            <x-signal.ui.table :caption="__('Accounts')">
                <x-slot:head><tr><th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Members') }}</th><th scope="col">{{ __('Projects') }}</th></tr></x-slot:head>
                @forelse ($accounts as $account)
                    <tr><td><a href="{{ route('admin.customers.accounts', $account->id) }}" class="font-bold text-primary hover:underline">{{ $account->name }}</a></td><td>{{ $account->memberships_count }}</td><td>{{ $account->projects_count }}</td></tr>
                @empty
                    <tr><td colspan="3" class="text-muted">{{ __('No account matches.') }}</td></tr>
                @endforelse
            </x-signal.ui.table>
        </div>
    @endif
</x-signal.layouts.admin>
