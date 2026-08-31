<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Providers')"
        :route="route('providers.index')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="$provider->name"
        :description="$provider->description"
    >
        <x-slot:buttons>
            <a href="{{ route('providers.edit', $provider) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Provider') }}
            </a>

            <x-dialogs.delete
                id="delete-provider"
                :route="route('providers.destroy', $provider)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this provider?')"
            ></x-dialogs.delete>

            <button type="submit" class="button primary" onclick="document.getElementById('delete-provider').showModal()">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Provider') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if ($errors->has('provider'))
        <div class="my-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            {{ $errors->first('provider') }}
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! List attached servers or repos for this token
     ! ------------------------------------------------------------
     !-->
    <div class="py-4 grid grid-cols-3 gap-6">

        @if($provider->isSourceControl())
            <div class="col-span-3 lg:col-span-1 space-y-4">
                <div class="p-4 bg-primary rounded-lg border shadow-md border-primary">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold leading-none text-primary">
                            {{ __('Repositories') }}
                        </h3>
                        <a href="{{ route('repositories.create') }}" class="text-ternary text-xs font-semibold underline">
                            {{ __('Add Repository') }}
                        </a>
                    </div>
                    <div class="flow-root">
                        <ul role="list" class="divide-y divide-primary">
                            @forelse($repositories as $repository)
                                <a href="{{ route('repositories.show', $repository) }}" class="py-3">
                                    <div class="flex items-center space-x-4">
                                        <div class="flex-shrink-0">
                                            <x-avatar :name="$repository->name" class="h-8 w-8 rounded-full text-xs" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-primary truncate">
                                                {{ $repository->name }}
                                            </p>
                                            <p class="text-sm truncate text-secondary">
                                                {{ $repository->url }}
                                            </p>
                                        </div>
                                        <div class="inline-flex items-center text-md font-semibold text-primary">
                                            {{ $repository->created_at->diffForHumans() }}
                                        </div>
                                    </div>
                                </a>
                            @empty
                                <x-alerts.info :title="__('No Repositories using this provider')"></x-alerts.info>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        @if(str($provider->provider)->contains(['digitalocean']))
            <div class="col-span-1 space-y-4">
                <div class="p-4 bg-primary rounded-lg border shadow-md border-primary">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold leading-none text-primary">
                            {{ __('Servers') }}
                        </h3>
                        <a href="{{ route('servers.create') }}" class="text-ternary text-xs font-semibold underline">
                            {{ __('Add Server') }}
                        </a>
                    </div>
                    <div class="flow-root">
                        <ul role="list" class="divide-y divide-primary">
                            @forelse($servers as $server)
                                <a href="{{ route('servers.show', $server) }}" class="py-3">
                                    <div class="flex items-center space-x-4">
                                        <div class="flex-shrink-0">
                                            <x-avatar :name="$server->name" class="h-8 w-8 rounded-full text-xs" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-primary truncate">
                                                {{ $server->name }}
                                            </p>
                                            <p class="text-sm truncate text-secondary">
                                                #{{ $server->identifier }}
                                            </p>
                                        </div>
                                        <div class="inline-flex items-center text-md font-semibold text-primary">
                                            {{ $server->created_at->diffForHumans() }}
                                        </div>
                                    </div>
                                </a>
                            @empty
                                <x-alerts.info :title="__('No Servers using this provider')"></x-alerts.info>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        @endif

    </div>

</x-layouts.app>
