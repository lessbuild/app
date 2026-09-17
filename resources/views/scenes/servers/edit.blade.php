<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :name', ['name' => $server->label])"
        :route="route('servers.show', $server)"
    />

    <div class="mx-auto max-w-3xl">
        <form action="{{ route('servers.update', $server) }}" method="POST">
            @csrf
            @method('PATCH')

            <x-ui.card class="mt-8 overflow-hidden">
                <div class="border-b border-primary px-5 py-5 sm:px-8">
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Infrastructure') }}</p>
                    <h1 class="mt-1 text-xl font-black text-primary">{{ __('Server display name') }}</h1>
                    <p class="mt-1 text-sm text-secondary">{{ __('Change the label shown in BuildPusher without renaming the cloud server or its hostname.') }}</p>
                </div>

                <div class="space-y-6 bg-primary px-5 py-5 sm:px-8">
                    <div>
                        <label class="block" for="display_name">
                            <span class="block text-sm font-semibold text-primary">{{ __('Display name') }}</span>
                            <input
                                class="input secondary mt-2 w-full rounded-lg"
                                id="display_name"
                                name="display_name"
                                type="text"
                                maxlength="80"
                                value="{{ old('display_name', $server->display_name) }}"
                                placeholder="{{ $server->name }}"
                                autofocus
                            >
                        </label>
                        <x-forms.errors name="display_name" />
                    </div>

                    <div class="rounded-xl border border-primary bg-secondary p-4 text-sm text-secondary">
                        <span class="font-semibold text-primary">{{ __('Cloud hostname:') }}</span>
                        <code class="ml-1 break-all">{{ $server->name }}</code>
                        <p class="mt-2">{{ __('Leave the display name empty to use this hostname throughout the control panel.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-8">
                    <x-ui.button :href="route('servers.show', $server)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Save display name') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>
</x-layouts.app>
