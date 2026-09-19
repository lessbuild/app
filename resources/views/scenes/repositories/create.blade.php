<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Repositories')"
        :route="route('repositories.index')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Check has provider
     ! ------------------------------------------------------------
     !-->
    @if($providers->isEmpty())
        <div class="my-4">
            <x-ui.alert tone="info" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You must add a source control provider before you can add a repository') }}</p>
                <x-ui.button :href="route('providers.index', ['dialog' => 'create-provider'])" variant="secondary">{{ __('Add source provider') }}</x-ui.button>
            </x-ui.alert>
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Check has servers
     ! ------------------------------------------------------------
     !-->
    @if($websites->isEmpty())
        <div class="my-4">
            <x-ui.alert tone="info" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You need an active website before you can add a repository') }}</p>
                <x-ui.button :href="route('websites.index', ['dialog' => 'create-website'])" variant="secondary">{{ __('Create Website') }}</x-ui.button>
            </x-ui.alert>
        </div>
    @endif

    <x-scenes.repositories.create-dialog
        :providers="$providers"
        :websites="$websites"
        :open="true"
        field-prefix=""
    />

</x-layouts.app>
