{{-- Edit and remove buttons for one task; $kind is its address segment and $editId its edit modal. --}}
<span class="flex shrink-0 items-center gap-2">
    @if ($task->status !== 'removing')
        <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => $editId])" variant="quiet" size="sm" :data-modal-trigger="$editId">{{ __('Edit') }}</x-signal.ui.button>
        <form method="POST" action="{{ route('infrastructure.servers.tasks.destroy', [$project, $server->id, $kind, $task->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
    @endif
</span>
