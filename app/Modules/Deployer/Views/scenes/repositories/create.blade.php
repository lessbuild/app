<x-layouts.app>
    @php
        $repositoryCreatePageUrl = request()->fullUrlWithoutQuery('dialog');
        $providerCreateUrl = (string) \Illuminate\Support\Uri::of($repositoryCreatePageUrl)->withQuery(['dialog' => 'create-provider']);
        $providerCreateContentUrl = route('dialogs.create', ['resource' => 'provider', 'return_to' => $repositoryCreatePageUrl]);
        $websiteCreateUrl = (string) \Illuminate\Support\Uri::of($repositoryCreatePageUrl)->withQuery(['dialog' => 'create-website']);
        $websiteCreateContentUrl = route('dialogs.create', ['resource' => 'website', 'return_to' => $repositoryCreatePageUrl]);
    @endphp

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
            <x-signal.ui.alert tone="info" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You must add a source control provider before you can add a repository') }}</p>
                <x-signal.ui.button :href="$providerCreateUrl" data-modal-trigger="provider-create-dialog" data-modal-content-url="{{ $providerCreateContentUrl }}" aria-controls="provider-create-dialog" aria-expanded="false" variant="secondary">{{ __('Add source provider') }}</x-signal.ui.button>
            </x-signal.ui.alert>
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Check has servers
     ! ------------------------------------------------------------
     !-->
    @if($websites->isEmpty())
        <div class="my-4">
            <x-signal.ui.alert tone="info" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You need an active website before you can add a repository') }}</p>
                <x-signal.ui.button :href="$websiteCreateUrl" data-modal-trigger="website-create-dialog" data-modal-content-url="{{ $websiteCreateContentUrl }}" aria-controls="website-create-dialog" aria-expanded="false" variant="secondary">{{ __('Create Website') }}</x-signal.ui.button>
            </x-signal.ui.alert>
        </div>
    @endif

    <x-scenes.repositories.create-dialog
        :providers="$providers"
        :websites="$websites"
        :open="true"
        field-prefix=""
    />

</x-layouts.app>
