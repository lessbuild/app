<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :route="route('servers.index')"
        :title="__('Back to servers')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Check has provider
     ! ------------------------------------------------------------
     !-->
    @if($providers->isEmpty())
        <div class="my-4">
            <x-alerts.info
                :title="__('You must add a provider before you can add a server')"
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
    <form action="{{ route('servers.store') }}" method="POST">
        @csrf
        <x-forms.section
            title="{{ __('Server Information') }}"
            description="{{ __('Please fill in the information below to create a new server.') }}"
        >
            <x-scenes.servers._form
                :types="$types"
                :providers="$providers"
                :sizes="$sizes"
                :images="$images"
                :regions="$regions"
                :recipes="$recipes"
            ></x-scenes.servers._form>

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="cursor-pointer button primary" type="submit">
                        <span class="flex items-center justify-between">
                            {{ __('Create Server') }}
                        </span>
                    </button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>

</x-layouts.app>
