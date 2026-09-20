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

        <div class="rounded-lg border border-primary bg-secondary p-3">
            <p class="font-semibold text-primary">{{ $member->name }}</p>
            <p class="mt-1 text-sm text-secondary">{{ $member->email }}</p>
        </div>

        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Workspace role') }}</span>
            <select id="{{ $id }}-role" name="role" class="input secondary w-full rounded-lg">
                @foreach (\App\Models\Organization::ROLES as $role)
                    <option value="{{ $role }}" @selected(old('role', $member->pivot->role) === $role)>{{ ucfirst($role) }}</option>
                @endforeach
            </select>
            <x-forms.errors name="role" />
        </label>

        <x-ui.button type="submit" variant="primary">{{ __('Save role') }}</x-ui.button>
    </form>
</x-dialogs.modal>
