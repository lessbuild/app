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
    <div class="mx-auto max-w-4xl">
        <form action="{{ route('websites.update', $website) }}" method="POST">
            @csrf
            @method('patch')

            <x-ui.card class="mt-8 overflow-hidden">
                <div class="border-b border-primary px-6 py-5 sm:px-8">
                    <h2 class="text-xl font-bold text-primary">{{ __('Website Information') }}</h2>
                    <p class="mt-1 text-sm text-secondary">{{ __('Please fill in the information below to update this website.') }}</p>
                </div>
            <x-scenes.websites._form
                :servers="$servers"
                :website="$website"
            ></x-scenes.websites._form>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-6 py-4 sm:px-8">
                    <x-ui.button :href="route('websites.show', $website)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Edit Website') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>

</x-layouts.app>
