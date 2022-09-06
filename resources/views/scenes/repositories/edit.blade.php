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
    <form action="{{ route('repositories.update', $repository) }}" method="POST">
        @csrf
        @method('patch')
        <x-forms.section
            title="{{ __('Repository Information') }}"
            description="{{ __('Please fill in the information below to update your repository.') }}"
        >
            <x-scenes.repositories._form
                :providers="$providers"
                :repository="$repository"
            ></x-scenes.repositories._form>

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="cursor-pointer button primary" type="submit">
                        <span class="flex items-center justify-between">
                            {{ __('Update Repository') }}
                        </span>
                    </button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>

</x-layouts.app>
