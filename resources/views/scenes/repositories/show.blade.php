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

            <form method="POST" action="{{ route('repositories.deploy', $repository) }}">
                @csrf
                <button type="submit" class="button primary" @disabled($deploymentInProgress || ! $deploymentReady)>
                    <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                        <use xlink:href="/assets/images/icons.svg#cloud-upload"></use>
                    </svg>
                    {{ ! $deploymentReady ? __('Deployment unavailable') : ($deploymentInProgress ? __('Deployment in progress') : __('Deploy')) }}
                </button>
            </form>

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

    @if (! $deploymentReady)
        <div class="my-4 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
            {{ __('The linked website and server must both be active before this repository can be deployed.') }}
        </div>
    @endif

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
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <span class="mr-1 text-primary">{{ __('Branch') }}</span>
            <span class="text-secondary">{{ $repository->branch }}</span>
        </div>
    </div>

    <div class="col-span-3">
        <livewire:repository-setup :model="$repository"></livewire:repository-setup>
    </div>

    <section class="mt-10">
        <h2 class="mb-4 text-2xl font-bold text-primary">{{ __('Deployment history') }}</h2>
        @forelse ($builds as $build)
            <div class="mb-3 flex items-center justify-between rounded-lg border border-primary bg-primary p-4">
                <div>
                    <a href="{{ route('builds.show', $build) }}" class="font-medium text-primary hover:underline">
                        {{ __('Build #:id', ['id' => $build->id]) }}
                    </a>
                    <p class="text-sm text-secondary">
                        {{ $build->created_at->diffForHumans() }}
                        @if ($build->failure_message)
                            &middot; {{ $build->failure_message }}
                        @endif
                    </p>
                </div>
                <span @class([
                    'rounded-full px-3 py-1 text-xs font-semibold uppercase',
                    'bg-green-100 text-green-700' => $build->status === \App\Models\Build::STATUS_SUCCEEDED,
                    'bg-red-100 text-red-700' => $build->status === \App\Models\Build::STATUS_FAILED,
                    'bg-blue-100 text-blue-700' => in_array($build->status, [\App\Models\Build::STATUS_DEPLOYING, \App\Models\Build::STATUS_RUNNING]),
                    'bg-gray-100 text-gray-700' => $build->status === \App\Models\Build::STATUS_QUEUED,
                ])>{{ $build->status }}</span>
            </div>
        @empty
            <x-lists.empty
                :title="__('No deployments yet')"
                :description="__('Deploy this repository to create its first build.')"
            />
        @endforelse
    </section>

</x-layouts.app>
