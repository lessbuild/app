<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Providers')"
        :route="route('providers.index')"
    />

    <x-scenes.providers.create-dialog :open="true" field-prefix="" />
</x-layouts.app>
