<x-layouts.app>

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
     ! Check has provider
     ! ------------------------------------------------------------
     !-->
    @if($providers->isEmpty())
        <div class="my-4">
            <x-alerts.info
                :title="__('You must add a cloud provider before you can add a server')"
                :link="route('providers.create')"
                :anchor="__('Add cloud provider')"
            ></x-alerts.info>
        </div>
    @endif

    @if(!$planUsage['allowed'])
        <div class="my-4"><x-alerts.info :title="__('Your plan’s server limit has been reached')" :link="route('billing.index')" :anchor="__('Upgrade plan')"></x-alerts.info></div>
    @endif
    @error('plan')<div class="my-4 rounded border border-red-300 bg-red-50 p-4 text-red-800">{{ $message }} <a class="font-bold underline" href="{{ route('billing.index') }}">{{ __('View plans') }}</a></div>@enderror

    <!--
     ! ------------------------------------------------------------
     ! Content
     ! ------------------------------------------------------------
     !-->
    <form action="{{ route('servers.store') }}" method="POST">
        @csrf
        <x-forms.section
            title="{{ __('Server Information') }}"
            description="{{ __('Please fill in the information below to create a new server.') }}"
        >
            <x-scenes.servers._form
                :types="$types"
                :providers="$providers"
                :sizes="$sizes"
                :images="$images"
                :regions="$regions"
                :recipes="$recipes"
            ></x-scenes.servers._form>

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="cursor-pointer button primary disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($providers->isEmpty() || !$planUsage['allowed'])>
                        <span class="flex items-center justify-between">
                            {{ __('Create Server') }}
                        </span>
                    </button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>

</x-layouts.app>
