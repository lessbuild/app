<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Websites')"
        :route="route('websites.index')"
    />

    <x-scenes.websites.create-dialog
        :servers="$servers"
        :plan-usage="$planUsage"
        :website-index-query="[]"
        :website-store-url="route('websites.store', ['dialog' => 'create-website'])"
        :open="true"
        field-prefix=""
    />
</x-layouts.app>
