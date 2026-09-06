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
    @error('plan')<div class="my-4 rounded-sm border border-red-300 bg-red-50 p-4 text-red-800">{{ $message }} <a class="font-bold underline" href="{{ route('billing.index') }}">{{ __('View plans') }}</a></div>@enderror

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <form action="{{ route('websites.store') }}" method="POST">
        @csrf
        <x-forms.section
            title="{{ __('Website Information') }}"
            description="{{ __('Please fill in the information below to create a new website.') }}"
        >
            <x-scenes.websites._form :servers="$servers"></x-scenes.websites._form>

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="cursor-pointer button primary disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($servers->isEmpty() || !$planUsage['allowed'])>
                        <span class="flex items-center justify-between">
                            {{ __('Create Website') }}
                        </span>
                    </button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>

</x-layouts.app>
