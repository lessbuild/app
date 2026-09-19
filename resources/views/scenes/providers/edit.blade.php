<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :name', ['name' => $provider->name])"
        :route="route('providers.show', $provider)"
    />

    <x-scenes.providers.edit-dialog :provider="$provider" :open="true" field-prefix="" />
</x-layouts.app>
