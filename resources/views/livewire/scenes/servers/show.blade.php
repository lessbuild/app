<div @if ($shouldPoll) wire:poll.5s @endif>

    <!--
     ! ------------------------------------------------------------
     ! Show root and other passwords
     ! ------------------------------------------------------------
     !-->
    @if(session()->has('root_password') || session()->has('mysql_password'))
        <div class="my-4">
            <x-alerts.info>
                <x-slot name="title">
                    The root password is: <b class="font-bold">{{ session()->get('root_password') }}</b> <br>
                    The root MYSQL password is: <b class="font-bold">{{ session()->get('mysql_password') }}</b> <br>
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
        :route="route('servers.index')"
        :title="__('Back to servers')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        icon="digital-ocean"
        :title="$server->label"
        :description="__('Easily manage :name', ['name' => $server->label])"
    >
        <x-slot:buttons>

            <a href="{{ route('servers.edit', $server) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Display Name') }}
            </a>

            <a href="{{ route('servers.commands.index', $server) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#clock"></use>
                </svg>
                {{ __('Command History') }}
            </a>

            <button
                type="button"
                class="button primary"
                wire:click="$dispatch('open-server-command')"
                @disabled($server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE)
            >
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#terminal"></use>
                </svg>
                {{ __('Run Command') }}
            </button>

            <x-dialogs.delete
                id="delete-server"
                :route="route('servers.destroy', $server)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this server?')"
            ></x-dialogs.delete>

            <button type="submit" class="button primary" onclick="document.getElementById('delete-server').showModal()">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Server') }}
            </button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if ($server->provisioning_status === \App\Models\Server::STATUS_FAILED)
        <div class="my-4 rounded border border-red-300 bg-red-50 p-4 text-red-700">
            <p class="font-semibold">{{ __('Server provisioning failed') }}</p>
            <p class="text-sm">{{ $server->provisioning_error }}</p>
            @error('retry')
                <p class="mt-2 text-sm font-semibold">{{ $message }}</p>
            @enderror
            @if ($server->provisioning_failure_phase === \App\Models\Server::FAILURE_INITIALIZATION)
                <form method="POST" action="{{ route('servers.initialization.retry', $server) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="button primary">{{ __('Retry initialization') }}</button>
                </form>
            @elseif ($server->provisioning_failure_phase === \App\Models\Server::FAILURE_REMOTE)
                <form method="POST" action="{{ route('servers.provisioning.retry', $server) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="button primary">{{ __('Resume provisioning') }}</button>
                </form>
            @endif
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Server information
     ! ------------------------------------------------------------
     !-->
    <div class="flex flex-col iems-start lg:flex-row lg:items-center mt-4 text-primary">
        @if (filled($server->display_name))
            <div class="flex items-center mr-6">
                <svg class="mr-2 w-4 h-4 text-gray-400">
                    <use xlink:href="/assets/images/icons.svg#server"></use>
                </svg>
                <span class="mr-1 text-primary">{{ __('Cloud hostname') }}</span>
                <span class="text-secondary">{{ $server->name }}</span>
            </div>
        @endif
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#globe-alt"></use>
            </svg>
            <span class="mr-1 text-primary">
                {{ __('Public IP') }}
            </span>
            <div class="text-secondary">
                <div class="-mx-1 px-1 rounded-sm cursor-pointer">
                    {{ $server->public_ip ?? 'Pending' }}
                </div>
            </div>
        </div>
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#server"></use>
            </svg>
            <span class="mr-1 text-primary">{{ __('Type') }}</span>
            <span class="text-secondary">{{ str($server->type->value)->replace('-', ' ')->title() }}</span>
        </div>
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#globe-alt"></use>
            </svg>
            <span class="mr-1 text-primary">
                {{ __('Private IP') }}
            </span>
            <div class="text-secondary">
                <div class="-mx-1 px-1 rounded-sm cursor-pointer">
                    {{ $server->private_ip ?? 'Pending' }}
                </div>
            </div>
        </div>
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#location-marker"></use>
            </svg>
            <span class="mr-1 text-primary">
                {{ __('Region') }}
            </span>
            <span class="text-secondary">
                {{ $server->region}}
            </span>
        </div>
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#key"></use>
            </svg>
            <span class="mr-1 text-primary">
                {{ __('Provider') }}
            </span>
            <a href="{{ route('providers.show', $server->provider) }}" class="text-secondary">
                {{ $server->provider->name }}
            </a>
        </div>
        <div class="flex items-center mr-6">
            <div class="inline-block">
                <div class="-mx-1 px-1 rounded-sm cursor-pointer" tabindex="0">
                    <div class="flex items-center">
                        <div class="">
                            <span class="flex items-center">
                                <svg class="mr-2 w-4 h-4 text-gray-400">
                                    <use xlink:href="/assets/images/icons.svg#server"></use>
                                </svg>
                                <span class="mr-1 text-primary">
                                    {{ __('Server ID') }}
                                </span>
                                <span class="text-secondary">
                                    {{ $server->identifier }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!--
     ! ------------------------------------------------------------
     ! Quick Actions
     ! ------------------------------------------------------------
     !-->
    <div class="pt-10 grid grid-cols-2 gap-6">

        <!--
         ! ------------------------------------------------------------
         ! Attached websites
         ! ------------------------------------------------------------
         !-->
        <div class="self-start col-span-2 lg:col-span-1 p-4 bg-primary rounded-lg border shadow-md border-primary">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold leading-none text-primary">
                    {{ __('Attached Websites') }}
                </h3>
            </div>
            <div class="flow-root">
                <div class="divide-y divide-primary">
                    @forelse($websites as $website)
                        <a href="{{ route('websites.show', $website) }}" class="block py-2">
                            <div class="flex items-center space-x-4">
                                <div class="flex-shrink-0">
                                    <x-avatar :name="$website->name" class="h-8 w-8 rounded text-xs" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-ternary truncate">
                                        {{ $website->name }}
                                    </p>
                                    <p class="text-sm truncate text-secondary">
                                        {{ $website->url }}
                                    </p>
                                </div>
                                <div class="inline-flex items-center text-md font-semibold text-primary dark:text-white">
                                    Deployed
                                </div>
                            </div>
                        </a>
                    @empty
                        <x-alerts.info :title="__('No websites attached to server')"></x-alerts.info>
                    @endforelse
                </div>
            </div>
        </div>

        @if ($recipes->isNotEmpty())
            <div class="self-start col-span-2 lg:col-span-1 p-4 bg-primary rounded-lg border shadow-md border-primary">
                <h3 class="mb-4 text-lg font-bold leading-none text-primary">{{ __('Provisioning Recipes') }}</h3>
                <div class="divide-y divide-primary">
                    @foreach ($recipes as $recipe)
                        <div class="py-3">
                            <p class="text-sm font-medium text-ternary">{{ $recipe['name'] }}</p>
                            @if ($recipe['description'])
                                <p class="mt-1 text-sm text-secondary">{{ $recipe['description'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="col-span-2">
            <div class="mb-3">
                <h3 class="text-lg font-bold leading-none text-primary">{{ __('Log snapshot overview') }}</h3>
                <p class="mt-1 text-sm text-secondary">{{ __('Current state across the five supported server log types.') }}</p>
            </div>
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                <div class="rounded-lg border border-primary bg-primary p-3">
                    <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Ready snapshots') }}</dt>
                    <dd class="mt-1 text-2xl font-bold text-primary">{{ $logMetrics['ready'] }}</dd>
                </div>
                <div class="rounded-lg border border-primary bg-primary p-3">
                    <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Queued snapshots') }}</dt>
                    <dd class="mt-1 text-2xl font-bold text-primary">{{ $logMetrics['queued'] }}</dd>
                </div>
                <div class="rounded-lg border border-primary bg-primary p-3">
                    <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Refreshing snapshots') }}</dt>
                    <dd class="mt-1 text-2xl font-bold text-primary">{{ $logMetrics['refreshing'] }}</dd>
                </div>
                <div class="rounded-lg border border-primary bg-primary p-3">
                    <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Failed snapshots') }}</dt>
                    <dd class="mt-1 text-2xl font-bold text-primary">{{ $logMetrics['failed'] }}</dd>
                </div>
                <div class="rounded-lg border border-primary bg-primary p-3">
                    <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Not collected') }}</dt>
                    <dd class="mt-1 text-2xl font-bold text-primary">{{ $logMetrics['missing'] }}</dd>
                </div>
                <div class="rounded-lg border border-primary bg-primary p-3">
                    <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Latest refresh') }}</dt>
                    <dd class="mt-1 text-lg font-bold text-primary">
                        {{ $logMetrics['latest_at']?->diffForHumans() ?? __('Not available') }}
                    </dd>
                </div>
            </dl>
        </div>

        <!--
         ! ------------------------------------------------------------
         ! Server logs
         ! ------------------------------------------------------------
         !-->
        <div class="self-start col-span-2 lg:col-span-1 coding inverse-toggle px-5 pt-4 shadow-lg text-primary text-sm font-mono subpixel-antialiased bg-primary pb-6 pt-4 rounded-lg leading-normal overflow-hidden border border-primary">
            <div class="flex justify-between">
                <div class="top mb-2 flex">
                    <div class="h-3 w-3 bg-red-500 rounded-full"></div>
                    <div class="ml-2 h-3 w-3 bg-orange-300 rounded-full"></div>
                    <div class="ml-2 h-3 w-3 bg-green-500 rounded-full"></div>
                </div>
                <div class="divide-x-2 divide-secondary gap-2">
                    <a href="?log=apt"
                        @class([
		                    'font-medium text-xs',
		                    'text-ternary' => $log === 'apt',
		                    'text-primary' => $log !== 'apt',
                        ])
                    >
                        {{ __('Apt') }}
                    </a>
                    <a href="?log=caddy"
                        @class([
		                    'pl-2 font-medium text-xs',
		                    'text-ternary' => $log === 'caddy',
		                    'text-primary' => $log !== 'caddy',
                        ])
                    >
                        {{ __('Caddy') }}
                    </a>
                    <a href="?log=mysql"
                        @class([
		                    'pl-2 font-medium text-xs',
		                    'text-ternary' => $log === 'mysql',
		                    'text-primary' => $log !== 'mysql',
                        ])
                    >
                        {{ __('Mysql') }}
                    </a>
                    <a href="?log=php"
                        @class([
		                    'pl-2 font-medium text-xs',
		                    'text-ternary' => $log === 'php',
		                    'text-primary' => $log !== 'php',
                        ])
                    >
                        {{ __('PHP') }}
                    </a>
                    <a href="?log=provisioning"
                        @class([
		                    'pl-2 font-medium text-xs',
		                    'text-ternary' => $log === 'provisioning',
		                    'text-primary' => $log !== 'provisioning',
                        ])
                    >
                        {{ __('Provisioning') }}
                    </a>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between gap-3">
                <div class="text-xs text-secondary">
                    @if ($logSnapshot?->refreshed_at)
                        {{ __('Updated :time', ['time' => $logSnapshot->refreshed_at->diffForHumans()]) }}
                    @else
                        {{ __('No snapshot has been collected yet.') }}
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    @if ($logSnapshot?->log !== null)
                        <a href="{{ route('servers.logs.download', ['server' => $server, 'type' => $log]) }}" class="text-xs font-medium text-ternary hover:underline">
                            {{ __('Download log') }}
                        </a>
                    @endif
                    <button
                        type="button"
                        class="button primary"
                        wire:click="refreshLogs"
                        wire:loading.attr="disabled"
                        wire:target="refreshLogs"
                        @disabled(
                            $server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE
                            || in_array($logSnapshot?->status, [\App\Models\ServerLogSnapshot::STATUS_QUEUED, \App\Models\ServerLogSnapshot::STATUS_REFRESHING], true)
                        )
                    >
                        {{ __('Refresh logs') }}
                    </button>
                </div>
            </div>
            <div class="mt-4 flex flex-col max-h-96 overflow-y-scroll">
                @if ($server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE && $log !== 'provisioning')
                    <div class="text-secondary">{{ __('Select Provisioning to view logs while setup is running.') }}</div>
                @else
                    @if ($errors->has('logs'))
                        <div class="mb-2 text-red-500">{{ $errors->first('logs') }}</div>
                    @elseif ($logSnapshot?->status === \App\Models\ServerLogSnapshot::STATUS_QUEUED)
                        <div class="mb-2 text-secondary">{{ __('Log refresh queued.') }}</div>
                    @elseif ($logSnapshot?->status === \App\Models\ServerLogSnapshot::STATUS_REFRESHING)
                        <div class="mb-2 text-secondary">{{ __('Refreshing this log snapshot…') }}</div>
                    @elseif ($logSnapshot?->status === \App\Models\ServerLogSnapshot::STATUS_FAILED)
                        <div class="mb-2 text-red-500">{{ $logSnapshot->error ?: __('Unable to retrieve logs.') }}</div>
                    @endif
                    @forelse($logs as $line)
                        @if($line === '') @continue @endif
                        <div class="w-full">
                            <span class="text-ternary">{{ $server->name }}:~$</span>
                            <span class="text-primary">{{ $line }}</span>
                        </div>
                    @empty
                        @unless (in_array($logSnapshot?->status, [\App\Models\ServerLogSnapshot::STATUS_QUEUED, \App\Models\ServerLogSnapshot::STATUS_REFRESHING], true))
                            <div class="flex">
                                <span class="text-ternary">{{ $server->name }}:~$</span>
                                <span class="flex-1 typing items-center pl-2">
                                    @if ($log === 'provisioning' && $server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE)
                                        {{ $server->provisioning_status === \App\Models\Server::STATUS_FAILED
                                            ? __('No provisioning output was received.')
                                            : __('Waiting for provisioning output…') }}
                                    @else
                                        {{ __('No logs to show') }}
                                    @endif
                                </span>
                            </div>
                        @endunless
                    @endforelse
                @endif
            </div>
        </div>

        <div class="col-span-2">
            <livewire:server-setup :model="$server"></livewire:server-setup>
        </div>

    </div>

    <div id="server-command">
        <livewire:server-command :model="$server"></livewire:server-command>
    </div>


</div>
