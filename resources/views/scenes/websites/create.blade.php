<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Websites')"
        :route="route('websites.index')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Check has server
     ! ------------------------------------------------------------
     !-->
    @if($servers->isEmpty())
        <div class="my-4">
            <x-alerts.info
                :title="__('You need an active application server with MySQL before you can add a website')"
                :link="route('servers.create')"
                :anchor="__('Create Server')"
            ></x-alerts.info>
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <form action="{{ route('websites.store') }}" method="POST">
        @csrf
        <x-forms.section
            title="{{ __('Website Information') }}"
            description="{{ __('Please fill in the information below to create a new website.') }}"
        >
            <x-scenes.websites._form :servers="$servers"></x-scenes.websites._form>

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="cursor-pointer button primary" type="submit" @disabled($servers->isEmpty())>
                        <span class="flex items-center justify-between">
                            {{ __('Create Website') }}
                        </span>
                    </button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>

</x-layouts.app>
