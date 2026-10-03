<x-signal.layouts.account :account="$account" :title="__('Account settings')" :description="__('The name everyone in :account sees, and deleting it.', ['account' => $account->name])">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.settings-section :title="__('Name')" :description="__('Shown in the account switcher, invitations and emails.')">
        <form method="POST" action="{{ route('account.settings.update') }}" class="grid gap-5 p-4 sm:p-6">
            @csrf
            @method('PUT')
            <x-signal.ui.input-field name="name" :label="__('Account name')" :value="$account->name" maxlength="100" required />
            <div><x-signal.ui.button type="submit" variant="primary">{{ __('Save name') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>

    @if ($canDelete)
        <x-signal.ui.settings-section :title="__('Delete this account')" :description="__('Deletes its members’ access, invitations, API tokens and audit log straight away. This can’t be undone.')">
            <form method="POST" action="{{ route('account.settings.destroy') }}" class="grid gap-4 p-4 sm:p-6">
                @csrf
                @method('DELETE')
                <x-signal.ui.input-field name="confirm_name" :label="__('Type :name to confirm', ['name' => $account->name])" autocomplete="off" required error-bag="deleteAccount" :restore="false" />
                <div><x-signal.ui.button type="submit" variant="danger">{{ __('Delete :name', ['name' => $account->name]) }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.account>
