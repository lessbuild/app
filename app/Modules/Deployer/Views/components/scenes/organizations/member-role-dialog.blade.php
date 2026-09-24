@props([
    'action',
    'id',
    'member',
    'open' => false,
])

<x-dialogs.modal
    :id="$id"
    :title="__('Edit member role')"
    :description="__('Choose the workspace access level for :name.', ['name' => $member->name])"
    :open="$open"
>
    <form method="POST" action="{{ $action }}" class="space-y-5">
        @csrf
        @method('PATCH')

        <x-signal.ui.panel class="ui-panel bg-surface-muted p-3">
            <p class="font-semibold text-ink">{{ $member->name }}</p>
            <p class="mt-1 text-sm text-muted">{{ $member->email }}</p>
        </x-signal.ui.panel>

        <label class="block">
            <span class="ui-label">{{ __('Workspace role') }}</span>
            <x-signal.ui.select id="{{ $id }}-role" name="role" class="ui-input w-full">
                @foreach (\App\Modules\Deployer\Models\Organization::ROLES as $role)
                    <option value="{{ $role }}" @selected(old('role', $member->pivot->role) === $role)>{{ ucfirst($role) }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="role" />
        </label>

        <x-signal.ui.button type="submit" variant="primary">{{ __('Save role') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
