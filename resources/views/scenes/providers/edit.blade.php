<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :name', ['name' => $provider->name])"
        :route="route('providers.show', $provider)"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <form action="{{ route('providers.update', $provider) }}" method="POST">
        @csrf
        @method('patch')
        <x-forms.section
            title="{{ __('Provider Information') }}"
            description="{{ __('Please fill in the information below to update your provider') }}"
        >
            <x-scenes.providers._form :provider="$provider"></x-scenes.providers._form>

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="cursor-pointer button primary" type="submit">
                        <span class="flex items-center justify-between">
                            {{ __('Update Provider') }}
                        </span>
                    </button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>

</x-layouts.app>
