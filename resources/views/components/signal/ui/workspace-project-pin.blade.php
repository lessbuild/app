@props([
    'workspace',
    'project',
    'state' => ['personal' => false, 'workspace' => false],
    'canManageWorkspace' => false,
    'returnView' => 'all',
])

<x-signal.ui.menu align="right" trigger-class="ui-btn ui-btn-ghost ui-btn-sm items-center" panel-class="min-w-56 p-0" data-signal-menu aria-label="{{ __('Project pin options') }}">
    <x-slot:trigger>
        <span>{{ $state['personal'] || $state['workspace'] ? __('Pinned') : __('Pin project') }}</span>
    </x-slot:trigger>

    <x-signal.ui.card class="grid gap-2 p-3">
        @foreach (['personal' => __('Pin for me'), 'workspace' => __('Pin for workspace')] as $visibility => $label)
            @if ($visibility === 'personal' || $canManageWorkspace)
                <form method="POST" action="{{ route('core.workspace.project-pins.'.($state[$visibility] ? 'destroy' : 'update'), [$workspace, $project, $visibility]) }}">
                    @csrf
                    @if ($state[$visibility])
                        @method('DELETE')
                    @else
                        @method('PUT')
                    @endif
                    <input type="hidden" name="return_view" value="{{ $returnView }}">
                    <x-signal.ui.button type="submit" variant="ghost" class="w-full justify-start" :aria-pressed="$state[$visibility] ? 'true' : 'false'">
                        {{ $state[$visibility] ? str_replace('Pin', 'Unpin', $label) : $label }}
                    </x-signal.ui.button>
                </form>
            @elseif ($state['workspace'])
                <x-signal.ui.badge tone="neutral">{{ __('Pinned for workspace') }}</x-signal.ui.badge>
            @endif
        @endforeach
    </x-signal.ui.card>
</x-signal.ui.menu>
