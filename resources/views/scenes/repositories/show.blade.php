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
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="$repository->name"
        :description="$repository->description"
    >
        <x-slot:buttons>

            <a href="{{ route('repositories.deploy', $repository) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Deploy') }}
            </a>

            <a href="{{ route('repositories.edit', $repository) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit') }}
            </a>

            <x-dialogs.delete
                id="delete-repository"
                :route="route('repositories.destroy', $repository)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this repository?')"
            ></x-dialogs.delete>

            <button type="submit" class="button primary" onclick="document.getElementById('delete-repository').showModal()">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    <!--
     ! ------------------------------------------------------------
     ! Repository information
     ! ------------------------------------------------------------
     !-->
    <div class="flex items-center mt-4 text-gray-500">
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <span class="mr-1 text-primary">
                {{ __('URL') }}
            </span>
            <div class="text-secondary">
                <div class="-mx-1 px-1 rounded-sm cursor-pointer">
                    {{ $repository->url }}
                </div>
            </div>
        </div>
    </div>

    <div class="col-span-3">
        <livewire:repository-setup :model="$repository"></livewire:repository-setup>
    </div>

</x-layouts.app>
