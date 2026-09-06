<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :name', ['name' => $server->label])"
        :route="route('servers.show', $server)"
    />

    <form action="{{ route('servers.update', $server) }}" method="POST">
        @csrf
        @method('PATCH')

        <x-forms.section
            :title="__('Server display name')"
            :description="__('Change the label shown in BuildPusher without renaming the cloud server or its hostname.')"
        >
            <div class="space-y-6 bg-primary px-4 py-5 sm:p-6">
                <label class="block" for="display_name">
                    <span class="block pb-1 text-sm text-secondary">{{ __('Display name') }}</span>
                    <input
                        class="input secondary w-full rounded"
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

                <div class="rounded border border-primary bg-secondary p-3 text-sm text-secondary">
                    <span class="font-medium text-primary">{{ __('Cloud hostname:') }}</span>
                    <code>{{ $server->name }}</code>
                    <p class="mt-1">{{ __('Leave the display name empty to use this hostname throughout the control panel.') }}</p>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex justify-end gap-3 bg-tertiary px-4 py-3 sm:px-6">
                    <a href="{{ route('servers.show', $server) }}" class="button secondary">{{ __('Cancel') }}</a>
                    <button class="button primary" type="submit">{{ __('Save display name') }}</button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>
</x-layouts.app>
