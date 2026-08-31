<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Show passwords
     ! ------------------------------------------------------------
     !-->
    @if(session()->has("website:{$website->id}:mysql_password"))
        <div class="my-4">
            <x-alerts.info>
                <x-slot name="title">
                    The root MYSQL password is: <b class="font-bold">
                        {{ session()->get("website:{$website->id}:mysql_password") }}
                    </b>
                    <br>
                    This will only be shown once, so please save these passwords somewhere safe.
                </x-slot>
            </x-alerts.info>
        </div>
    @endif

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
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        icon="external-link"
        :title="$website->name"
        :description="$website->description"
    >
        <x-slot:buttons>
            <a href="{{ route('websites.edit', $website) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Website') }}
            </a>

            <x-dialogs.delete
                id="delete-website"
                :route="route('websites.destroy', $website)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this website?')"
            ></x-dialogs.delete>

            <button type="submit" class="button primary" onclick="document.getElementById('delete-website').showModal()">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Website') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if ($website->provisioning_status === \App\Models\Website::STATUS_FAILED)
        <div class="my-4 rounded border border-red-300 bg-red-50 p-4 text-red-700">
            <p class="font-semibold">{{ __('Website provisioning failed') }}</p>
            <p class="text-sm">{{ $website->provisioning_error }}</p>
            @error('retry')
                <p class="mt-2 text-sm font-semibold">{{ $message }}</p>
            @enderror
            <form method="POST" action="{{ route('websites.provisioning.retry', $website) }}" class="mt-3">
                @csrf
                <button type="submit" class="button primary">{{ __('Retry provisioning') }}</button>
            </form>
        </div>
    @endif

    @if ($website->previous_server_id)
        <div class="my-4 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
            <p class="font-semibold">{{ __('Previous server cleanup pending') }}</p>
            <p class="text-sm">
                @if ($website->placement_cleanup_error)
                    {{ $website->placement_cleanup_error }}
                @elseif ($website->provisioning_status === \App\Models\Website::STATUS_ACTIVE)
                    {{ __('The website is active on its target server and cleanup of its old placement is queued.') }}
                @elseif ($website->provisioning_status === \App\Models\Website::STATUS_FAILED)
                    {{ __('Target provisioning failed. The source placement was retained so you can retry safely.') }}
                @else
                    {{ __('The source placement remains available until target provisioning succeeds.') }}
                @endif
            </p>
            @if ($website->placement_cleanup_error)
                <form method="POST" action="{{ route('websites.placement.cleanup', $website) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="button primary">{{ __('Retry cleanup') }}</button>
                </form>
            @endif
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Website information
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
                    {{ $website->url }}
                </div>
            </div>
        </div>
        <div class="flex items-center mr-6">
            <span class="mr-1 text-primary">{{ __('Deployment health check') }}</span>
            <span class="text-secondary">
                {{ $website->health_check_enabled ? $website->health_check_path : __('Disabled') }}
            </span>
        </div>
        @if ($website->health_check_enabled)
            <div class="flex items-center mr-6">
                <span class="mr-1 text-primary">{{ __('Current health') }}</span>
                <span @class([
                    'font-medium',
                    'text-green-600' => $website->health_status === \App\Models\Website::HEALTH_HEALTHY,
                    'text-red-600' => $website->health_status === \App\Models\Website::HEALTH_UNHEALTHY,
                    'text-secondary' => $website->health_status === \App\Models\Website::HEALTH_UNKNOWN,
                ])>{{ str($website->health_status)->title() }}</span>
                @if ($website->health_last_checked_at)
                    <span class="ml-1 text-secondary">({{ $website->health_last_checked_at->diffForHumans() }})</span>
                @endif
            </div>
        @endif
        <div class="flex items-center mr-6">
            <span class="mr-1 text-primary">{{ __('Retained releases') }}</span>
            <span class="text-secondary">{{ $website->release_retention }}</span>
        </div>
    </div>

    @if ($website->health_status === \App\Models\Website::HEALTH_UNHEALTHY && $website->health_last_error)
        <div class="mt-4 rounded border border-red-300 bg-red-50 p-4 text-red-800">
            <strong>{{ __('Health check failed:') }}</strong> {{ $website->health_last_error }}
        </div>
    @endif

    <livewire:website-provisioning-log :website="$website" />

    <!--
     ! ------------------------------------------------------------
     ! Quick Actions
     ! ------------------------------------------------------------
     !-->
    <div class="py-4 grid grid-cols-3 gap-6">
        <div class="col-span-3 lg:col-span-1 space-y-4">
            <div class="p-4 bg-primary rounded-lg border shadow-md border-primary">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold leading-none text-primary">
                        {{ __('Attached Repositories') }}
                    </h3>
                    <a href="{{ route('repositories.create') }}" class="text-ternary text-xs font-semibold underline">
                        Add Repo
                    </a>
                </div>
                <div class="flow-root">
                    <ul role="list" class="divide-y divide-primary">
                        @forelse($repositories as $repository)
                            <a href="{{ route('repositories.show', $repository) }}" class="py-3">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0">
                                        <img
                                            class="w-8 h-8 rounded-full"
                                            src="https://ui-avatars.com/api/?name={{ $repository->name }}&size=128&background=1e293b&color=fff"
                                        >
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-ternary truncate">
                                            {{ $repository->name }}
                                        </p>
                                        <p class="text-sm truncate text-secondary">
                                            {{ $repository->url }}
                                        </p>
                                    </div>
                                    <div class="inline-flex items-center text-md font-semibold text-primary dark:text-white uppercase">
                                        {{ $repository->latestBuild?->status ?? __('Not deployed') }}
                                    </div>
                                </div>
                            </a>
                        @empty
                            <x-alerts.info :title="__('No repositories attached to server')"></x-alerts.info>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!--
     ! ------------------------------------------------------------
     ! Website Setup
     ! ------------------------------------------------------------
     !-->
    <livewire:website-setup :model="$website" />

</x-layouts.app>
