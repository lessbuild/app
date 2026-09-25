<x-signal.layouts.core-admin
    :title="__('Business analytics')"
    :description="__('Private platform-wide growth, usage, revenue, and monetization signals.')"
>
    <x-scenes.admin.business-analytics
        :totals="$totals"
        :plans="$plans"
        :trend="$trend"
    />
</x-signal.layouts.core-admin>
