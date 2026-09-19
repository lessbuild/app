<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :name', ['name' => $repository->name])"
        :route="route('repositories.show', $repository)"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Check has provider
     ! ------------------------------------------------------------
     !-->
    @if($providers->isEmpty())
        <div class="my-4">
            <x-ui.alert tone="info" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You must add a provider before you can add a repository') }}</p>
                <x-ui.button :href="route('providers.index', ['dialog' => 'create-provider'])" variant="secondary">{{ __('Add Provider') }}</x-ui.button>
            </x-ui.alert>
        </div>
    @endif

    <x-scenes.repositories.edit-dialog
        :providers="$providers"
        :repository="$repository"
        :websites="$websites"
        :open="true"
        field-prefix=""
    />

</x-layouts.app>
