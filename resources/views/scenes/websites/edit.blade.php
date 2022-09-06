<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :name', ['name' => $website->name])"
        :route="route('websites.show', $website)"
    >
    </x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <form action="{{ route('websites.update', $website) }}" method="POST">
        @csrf
        @method('patch')

        <x-forms.section
            title="{{ __('Website Information') }}"
            description="{{ __('Please fill in the information below to create a new website.') }}"
        >
            <x-scenes.websites._form
                :servers="$servers"
                :website="$website"
            ></x-scenes.websites._form>

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="cursor-pointer button primary" type="submit">
                        <span class="flex items-center justify-between">
                            {{ __('Edit Website') }}
                        </span>
                    </button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>

</x-layouts.app>
