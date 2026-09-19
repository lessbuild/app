<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :name', ['name' => $website->name])"
        :route="route('websites.show', $website)"
    />

    <x-scenes.websites.edit-dialog :servers="$servers" :website="$website" :open="true" field-prefix="" />
</x-layouts.app>
