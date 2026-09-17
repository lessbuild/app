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

    @if(!$planUsage['allowed'])
        <div class="my-4"><x-alerts.info :title="__('Your plan’s website limit has been reached')" :link="route('billing.index')" :anchor="__('Upgrade plan')"></x-alerts.info></div>
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
