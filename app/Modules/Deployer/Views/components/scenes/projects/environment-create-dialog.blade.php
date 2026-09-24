@props([
    'project',
    'open' => false,
])

<x-dialogs.modal
    id="add-environment-dialog"
    :title="__('Add environment')"
    :description="__('Create a separate runtime for a branch, then attach infrastructure and source control.')"
    :open="$open"
>
    <form method="POST" action="{{ route('environments.store', $project) }}" class="grid gap-3 sm:grid-cols-2">
        @csrf
        <x-signal.ui.input type="hidden" name="_environment_form" value="add" :restore="false" />
        <label>
            <span class="ui-label">{{ __('Name') }}</span>
            <x-signal.ui.input name="name" value="{{ old('name') }}" placeholder="Staging" class="ui-input" required :restore="false" />
            <x-forms.errors name="name" />
        </label>
        <label>
            <span class="ui-label">{{ __('Type') }}</span>
            <x-signal.ui.select name="type" class="ui-input">
                @foreach (\App\Modules\Deployer\Models\Environment::TYPES as $type)
                    <option value="{{ $type }}" @selected(old('type', 'staging') === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="type" />
        </label>
        <label class="sm:col-span-2">
            <span class="ui-label">{{ __('Branch') }}</span>
            <x-signal.ui.input name="branch" value="{{ old('branch') }}" placeholder="develop" class="ui-input" required :restore="false" />
            <x-forms.errors name="branch" />
        </label>
        <x-signal.ui.input type="hidden" name="is_protected" value="0" :restore="false" />
        <x-signal.ui.input type="hidden" name="requires_deployment_approval" value="0" :restore="false" />
        <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Create environment') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
