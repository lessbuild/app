@if(in_array('stats', $dashboardWidgets, true))
    <dl data-dashboard-stats class="ui-insight-grid mb-8 grid grid-cols-2 gap-2 sm:mb-12 sm:grid-cols-2 sm:gap-3 lg:grid-cols-4" aria-label="{{ __('Workspace totals') }}">
        <x-ui.stat :value="$stats['websites']" :label="__('Websites')" :description="__('Hosted application targets')" />
        <x-ui.stat :value="$stats['servers']" :label="__('Servers')" :description="__('Provisioned compute')" />
        <x-ui.stat :value="$stats['builds']" :label="__('Builds')" :description="__('Recorded releases')" />
        <x-ui.stat :value="$stats['repositories']" :label="__('Repositories')" :description="__('Connected sources')" />
    </dl>
@endif
