<x-layouts.app>

    <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 -mx-3 mb-20">
        <!--
         ! ------------------------------------------------------------
         ! Build statistics
         ! ------------------------------------------------------------
         !-->
        <x-panel.stats
            icon="link"
            :title="auth()->user()->websites()->count()"
            description="{{ __('Websites') }}"></x-panel.stats>
        <!--
         ! ------------------------------------------------------------
         ! Server statistics
         ! ------------------------------------------------------------
         !-->
        <x-panel.stats
            icon="cloud"
            :title="auth()->user()->servers()->count()"
            description="{{ __('Servers') }}"></x-panel.stats>

        <!--
         ! ------------------------------------------------------------
         ! Build statistics
         ! ------------------------------------------------------------
         !-->
        <x-panel.stats
            icon="cloud-upload"
            title="3"
            description="{{ __('Builds') }}"></x-panel.stats>

        <!--
         ! ------------------------------------------------------------
         ! Repository statistics
         ! ------------------------------------------------------------
         !-->
        <x-panel.stats
            icon="code"
            :title="auth()->user()->repositories()->count()"
            description="{{ __('Repos') }}"></x-panel.stats>
    </div>

</x-layouts.app>
