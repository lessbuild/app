<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :name', ['name' => $server->label])"
        :route="route('servers.show', $server)"
    />

    <x-scenes.servers.edit-dialog :server="$server" :open="true" :title="__('Server display name')" />
</x-layouts.app>
