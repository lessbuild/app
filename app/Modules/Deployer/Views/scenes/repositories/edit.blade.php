<x-layouts.app>
    @php
        $repositoryEditPageUrl = request()->fullUrlWithoutQuery('dialog');
        $providerCreateUrl = (string) \Illuminate\Support\Uri::of($repositoryEditPageUrl)->withQuery(['dialog' => 'create-provider']);
        $providerCreateContentUrl = route('dialogs.create', ['resource' => 'provider', 'return_to' => $repositoryEditPageUrl]);
    @endphp

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
            <x-signal.ui.alert tone="info" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You must add a provider before you can add a repository') }}</p>
                <x-signal.ui.button :href="$providerCreateUrl" data-modal-trigger="provider-create-dialog" data-modal-content-url="{{ $providerCreateContentUrl }}" aria-controls="provider-create-dialog" aria-expanded="false" variant="secondary">{{ __('Add Provider') }}</x-signal.ui.button>
            </x-signal.ui.alert>
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
