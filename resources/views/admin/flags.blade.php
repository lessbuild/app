@php($states = ['off' => __('Off'), 'on' => __('On for everyone'), 'accounts' => __('On for chosen accounts')])
<x-signal.layouts.admin :title="__('Feature flags')" :description="__('Code asks FeatureFlags::enabled(\'key\', $account). A key with no flag is off. Every change goes in the admin trail.')">
    @foreach (['key', 'description', 'state', 'account_ids'] as $field)
        @error($field)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach

    @forelse ($flags as $flag)
        <x-signal.ui.settings-section :title="$flag->key" :description="__('Changed :when by :name', ['when' => $flag->updated_at?->diffForHumans(), 'name' => $flag->editor->name ?? __('someone')])">
            <form method="POST" action="{{ route('admin.flags.update', $flag->id) }}" class="grid items-start gap-4 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @method('PUT')
                <x-signal.ui.select-field :id="'state-'.$flag->id" name="state" :label="__('State')">
                    @foreach ($states as $value => $label)
                        <option value="{{ $value }}" @selected($flag->state === $value)>{{ $label }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.input-field :id="'description-'.$flag->id" name="description" :label="__('What it switches')" :value="$flag->description" maxlength="255" :restore="false" required />
                <div class="sm:col-span-2"><x-signal.ui.textarea-field :id="'accounts-'.$flag->id" name="account_ids" :label="__('Account IDs (for “chosen accounts”)')" rows="2" :value="implode(PHP_EOL, $flag->account_ids ?? [])" :restore="false" :description="__('One a line. Find IDs under Customers.')" /></div>
                <div class="flex gap-2 sm:col-span-2">
                    <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                </div>
            </form>
            <form method="POST" action="{{ route('admin.flags.destroy', $flag->id) }}" class="px-4 pb-4 sm:px-6">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Delete flag') }}</x-signal.ui.button></form>
        </x-signal.ui.settings-section>
    @empty
        <x-signal.ui.empty-state icon="filter" :title="__('No flags yet')" :description="__('Add one below when code needs a switch.')" />
    @endforelse

    <x-signal.ui.settings-section :title="__('New flag')" :description="__('New flags start off.')">
        <form method="POST" action="{{ route('admin.flags.store') }}" class="grid items-start gap-4 p-4 sm:grid-cols-2 sm:p-6">
            @csrf
            <x-signal.ui.input-field name="key" :label="__('Key')" placeholder="deploy.new-scheduler" maxlength="60" required />
            <x-signal.ui.input-field name="description" :label="__('What it switches')" maxlength="255" required />
            <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Add flag') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>
</x-signal.layouts.admin>
