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
        <input type="hidden" name="_server_display_name_form" value="1">
        <label class="block" for="server-display-name">
            <span class="block text-sm font-semibold text-primary">{{ __('Display name') }}</span>
            <input
                class="input secondary mt-2 w-full rounded-lg"
                id="server-display-name"
                name="display_name"
                type="text"
                maxlength="80"
                value="{{ old('display_name', $server->display_name) }}"
                placeholder="{{ $server->name }}"
                autofocus
            >
            <x-forms.errors name="display_name" />
        </label>
        <div class="rounded-xl border border-primary bg-secondary p-4 text-sm text-secondary">
            <span class="font-semibold text-primary">{{ __('Cloud hostname:') }}</span>
            <code class="ml-1 break-all">{{ $server->name }}</code>
            <p class="mt-2">{{ __('Leave the display name empty to use this hostname throughout the control panel.') }}</p>
        </div>
        <x-ui.button type="submit" variant="primary">{{ __('Save display name') }}</x-ui.button>
    </form>
</x-dialogs.modal>
