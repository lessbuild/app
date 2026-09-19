<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    <x-scenes.recipes.create-dialog :open="true" field-prefix="" />
</x-layouts.app>
