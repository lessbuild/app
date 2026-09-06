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
            <x-alerts.info
                :title="__('You must add a source control provider before you can add a repository')"
                :link="route('providers.create')"
                :anchor="__('Add source provider')"
            ></x-alerts.info>
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Check has servers
     ! ------------------------------------------------------------
     !-->
    @if($websites->isEmpty())
        <div class="my-4">
            <x-alerts.info
                :title="__('You need an active website before you can add a repository')"
                :link="route('websites.create')"
                :anchor="__('Create Website')"
            ></x-alerts.info>
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <form action="{{ route('repositories.store') }}" method="POST">
        @csrf
        <x-forms.section
            title="{{ __('Repository Information') }}"
            description="{{ __('Please fill in the information below to add a new repository.') }}"
        >
            <x-scenes.repositories._form
                :providers="$providers"
                :websites="$websites"
            ></x-scenes.repositories._form>

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="cursor-pointer button primary disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($providers->isEmpty() || $websites->isEmpty())>
                        <span class="flex items-center justify-between">
                            {{ __('Create Repository') }}
                        </span>
                    </button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>

</x-layouts.app>
