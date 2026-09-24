@props([
    'build',
    'open' => false,
    'targets',
])

@php($dialogId = 'promotion-dialog-'.$build->id)

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Promote tested release')"
    :description="__('Rebuild exact revision :revision with the target environment configuration.', ['revision' => $build->shortRevision()])"
    :open="$open"
>
    <form method="POST" action="{{ route('builds.promote', $build) }}" class="space-y-4">
        @csrf
        <x-signal.ui.input type="hidden" name="_promotion_build_id" value="{{ $build->id }}" :restore="false" />
        <label class="block">
            <span class="ui-label">{{ __('Target environment') }}</span>
            <x-signal.ui.select name="target_environment_id" required class="ui-input">
                <option value="">{{ __('Choose target') }}</option>
                @foreach($targets as $target)
                    <option value="{{ $target->id }}" @selected((string) old('target_environment_id') === (string) $target->id)>{{ $target->name }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="target_environment_id" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Change ticket or release note') }}</span>
            <x-signal.ui.input name="promotion_note" value="{{ old('promotion_note') }}" maxlength="2000" class="ui-input" placeholder="{{ __('Optional release note') }}" :restore="false" />
            <x-forms.errors name="promotion_note" />
        </label>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Promote') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
