<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('websites.index')" :title="__('Back to websites')" />
    <x-layouts.partials.heading
        :title="__('Import existing application')"
        :description="__('Adopt an application already stored under /var/www on an active server. :app verifies the directory and does not change files or proxy configuration during import.', ['app' => config('app.name')])"
    />

    <div class="mx-auto max-w-3xl">
        <form method="POST" action="{{ route('websites.import.store') }}" class="mt-8">
            @csrf
            <x-ui.card class="overflow-hidden">
                <div class="space-y-6 bg-primary px-5 py-5 sm:px-8">
                    <div>
                        <label for="server_id" class="block text-sm font-semibold text-primary">{{ __('Active server') }}</label>
                        <select id="server_id" name="server_id" required class="input secondary mt-2 w-full rounded-lg">
                            <option value="">{{ __('Choose a server') }}</option>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}" @selected(old('server_id') == $server->id)>{{ $server->label }} · {{ $server->public_ip }}</option>
                            @endforeach
                        </select>
                        <x-forms.errors name="server_id" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="name" class="block text-sm font-semibold text-primary">{{ __('Application name') }}</label>
                            <input id="name" name="name" required maxlength="100" value="{{ old('name') }}" class="input secondary mt-2 w-full rounded-lg">
                            <x-forms.errors name="name" />
                        </div>
                        <div>
                            <label for="url" class="block text-sm font-semibold text-primary">{{ __('Domain') }}</label>
                            <input id="url" name="url" required maxlength="255" value="{{ old('url') }}" placeholder="app.example.com" class="input secondary mt-2 w-full rounded-lg">
                            <x-forms.errors name="url" />
                        </div>
                    </div>

                    <div>
                        <label for="deployment_slug" class="block text-sm font-semibold text-primary">{{ __('Directory name under /var/www') }}</label>
                        <div class="mt-2 flex">
                            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-primary bg-secondary px-3 text-sm text-secondary">/var/www/</span>
                            <input id="deployment_slug" name="deployment_slug" required maxlength="32" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="{{ old('deployment_slug') }}" placeholder="my-app" class="input secondary min-w-0 flex-1 rounded-l-none rounded-r-lg">
                        </div>
                        <x-forms.errors name="deployment_slug" />
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-semibold text-primary">{{ __('Description') }}</label>
                        <textarea id="description" name="description" required maxlength="1000" rows="4" class="input secondary mt-2 w-full rounded-lg">{{ old('description') }}</textarea>
                        <x-forms.errors name="description" />
                    </div>

                    <x-ui.alert tone="info">
                        {{ __('Health monitoring starts disabled so importing cannot generate a false incident. Enable it after confirming the domain and health path.') }}
                    </x-ui.alert>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-8">
                    <x-ui.button :href="route('websites.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" :disabled="! $planUsage['allowed'] || $servers->isEmpty()">{{ __('Verify and import') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>
</x-layouts.app>
