@props([
    'server',
    'open' => false,
    'title' => null,
])

@php($dialogTitle = $title ?? __('Edit server display name'))

<x-dialogs.modal
    id="server-display-name-dialog"
    :title="$dialogTitle"
    :description="__('Change the label shown in :app without renaming the cloud server or its hostname.', ['app' => config('app.name')])"
    :open="$open"
>
    <form action="{{ route('servers.update', $server) }}" method="POST" class="space-y-5">
        @csrf
        @method('PATCH')
        <x-signal.ui.input type="hidden" name="_server_display_name_form" value="1" :restore="false" />
        <x-signal.ui.input-field
            id="server-display-name"
            name="display_name"
            :label="__('Display name')"
            :value="$server->display_name"
            maxlength="80"
            :placeholder="$server->name"
            autofocus
            class="w-full"
        />
        <x-signal.ui.card tone="muted" class="bg-surface-muted p-4 text-sm text-muted" :shadow="false">
            <span class="font-semibold text-ink">{{ __('Cloud hostname:') }}</span>
            <code class="ml-1 break-all">{{ $server->name }}</code>
            <p class="mt-2">{{ __('Leave the display name empty to use this hostname throughout the control panel.') }}</p>
        </x-signal.ui.card>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save display name') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
