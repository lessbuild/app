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
        <input type="hidden" name="_promotion_build_id" value="{{ $build->id }}">
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Target environment') }}</span>
            <select name="target_environment_id" required class="input secondary w-full rounded-lg">
                <option value="">{{ __('Choose target') }}</option>
                @foreach($targets as $target)
                    <option value="{{ $target->id }}" @selected((string) old('target_environment_id') === (string) $target->id)>{{ $target->name }}</option>
                @endforeach
            </select>
            <x-forms.errors name="target_environment_id" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Change ticket or release note') }}</span>
            <input name="promotion_note" value="{{ old('promotion_note') }}" maxlength="2000" class="input secondary w-full rounded-lg" placeholder="{{ __('Optional release note') }}">
            <x-forms.errors name="promotion_note" />
        </label>
        <x-ui.button type="submit" variant="primary">{{ __('Promote') }}</x-ui.button>
    </form>
</x-dialogs.modal>
