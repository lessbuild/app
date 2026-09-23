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

        <div class="ui-panel bg-surface-muted p-3">
            <p class="font-semibold text-ink">{{ $member->name }}</p>
            <p class="mt-1 text-sm text-muted">{{ $member->email }}</p>
        </div>

        <label class="block">
            <span class="ui-label">{{ __('Workspace role') }}</span>
            <select id="{{ $id }}-role" name="role" class="ui-input w-full">
                @foreach (\App\Modules\Deployer\Models\Organization::ROLES as $role)
                    <option value="{{ $role }}" @selected(old('role', $member->pivot->role) === $role)>{{ ucfirst($role) }}</option>
                @endforeach
            </select>
            <x-forms.errors name="role" />
        </label>

        <x-ui.button type="submit" variant="primary">{{ __('Save role') }}</x-ui.button>
    </form>
</x-dialogs.modal>
