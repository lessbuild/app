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
            <x-alerts.info
                :title="__('You must add a provider before you can add a repository')"
                :link="route('providers.create')"
                :anchor="__('Add Provider')"
            ></x-alerts.info>
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <div class="mx-auto max-w-4xl">
        <form action="{{ route('repositories.update', $repository) }}" method="POST">
            @csrf
            @method('patch')
            <x-ui.card class="mt-8 overflow-hidden">
                <div class="border-b border-primary px-6 py-5 sm:px-8">
                    <h2 class="text-xl font-bold text-primary">{{ __('Repository Information') }}</h2>
                    <p class="mt-1 text-sm text-secondary">{{ __('Please fill in the information below to update your repository.') }}</p>
                </div>
            <x-scenes.repositories._form
                :providers="$providers"
                :repository="$repository"
                :websites="$websites"
            ></x-scenes.repositories._form>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-6 py-4 sm:px-8">
                    <x-ui.button :href="route('repositories.show', $repository)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Update Repository') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>

</x-layouts.app>
