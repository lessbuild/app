<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :route="route('servers.index')"
        :title="__('Back to servers')"
    />

    <x-scenes.servers.create-dialog
        :types="$types"
        :providers="$providers"
        :sizes="$sizes"
        :images="$images"
        :regions="$regions"
        :recipes="$recipes"
        :plan-usage="$planUsage"
        :open="true"
        field-prefix=""
    />
</x-layouts.app>
