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

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <div class="mx-auto max-w-4xl">
        <form action="{{ route('repositories.store') }}" method="POST">
            @csrf
            <x-ui.card class="mt-8 overflow-hidden">
                <div class="border-b border-primary px-6 py-5 sm:px-8">
                    <h2 class="text-xl font-bold text-primary">{{ __('Repository Information') }}</h2>
                    <p class="mt-1 text-sm text-secondary">{{ __('Please fill in the information below to add a new repository.') }}</p>
                </div>
                <x-scenes.repositories._form
                    :providers="$providers"
                    :websites="$websites"
                />

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-6 py-4 sm:px-8">
                    <x-ui.button :href="route('repositories.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" :disabled="$providers->isEmpty() || $websites->isEmpty()">
                        {{ __('Create Repository') }}
                    </x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>

</x-layouts.app>
