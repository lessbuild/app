<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('projects.index')" :title="__('Back to applications')" />

    <x-scenes.projects.create-dialog :templates="$templates" :open="true" />
</x-layouts.app>
