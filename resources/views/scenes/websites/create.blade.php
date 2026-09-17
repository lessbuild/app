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
        <x-ui.alert tone="info" class="my-4" role="status">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You need an active application server with MySQL before you can add a website') }}</p>
                <a data-turbo="false" href="{{ route('servers.create') }}" class="shrink-0 font-semibold underline">
                    {{ __('Create Server') }}
                    <span aria-hidden="true">→</span>
                </a>
            </div>
        </x-ui.alert>
    @endif

    @if(!$planUsage['allowed'])
        <x-ui.alert tone="warning" class="my-4" role="status">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('Your plan’s website limit has been reached') }}</p>
                <a data-turbo="false" href="{{ route('billing.index') }}" class="shrink-0 font-semibold underline">
                    {{ __('Upgrade plan') }}
                    <span aria-hidden="true">→</span>
                </a>
            </div>
        </x-ui.alert>
    @endif
    @error('plan')
        <x-ui.alert tone="danger" class="my-4">{{ $message }} <a class="font-bold underline" href="{{ route('billing.index') }}">{{ __('View plans') }}</a></x-ui.alert>
    @enderror

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <div class="mx-auto max-w-4xl">
        <form action="{{ route('websites.store') }}" method="POST">
            @csrf
            <x-ui.card class="mt-8 overflow-hidden">
                <div class="border-b border-primary px-6 py-5 sm:px-8">
                    <h2 class="text-xl font-bold text-primary">{{ __('Website Information') }}</h2>
                    <p class="mt-1 text-sm text-secondary">{{ __('Please fill in the information below to create a new website.') }}</p>
                </div>
            <x-scenes.websites._form :servers="$servers"></x-scenes.websites._form>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-6 py-4 sm:px-8">
                    <x-ui.button :href="route('websites.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" :disabled="$servers->isEmpty() || ! $planUsage['allowed']">
                        {{ __('Create Website') }}
                    </x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>

</x-layouts.app>
