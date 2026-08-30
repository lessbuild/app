<div class="px-4 py-5 bg-primary space-y-6 sm:p-6">

    <div class="col-span-3 sm:col-span-2">
        <label for="server_id" class="block text-sm font-medium text-primary">
            {{ __('Server') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select name="server_id" class="input secondary rounded">
                @foreach($servers as $server)
                    <option value="{{ $server->id }}"
                        @selected((string) old('server_id', $website->server_id ?? '') === (string) $server->id)
                    >
                        {{ $server->name }} ({{ str($server->type->value)->replace('-', ' ')->title() }})
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="server_id"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="name" class="block text-sm font-medium text-primary">
            {{ __('Website Name') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <input
                value="{{ old('name') ?? ($website->name ?? null) }}"
                type="text"
                name="name"
                id="name"
                class="input secondary rounded"
                placeholder="Example: Deployer">
        </div>
        <x-forms.errors name="name"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="url" class="block text-sm font-medium text-primary">
            {{ __('Website URL') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-primary bg-tertiary text-primary text-sm">
                http://
            </span>
            <input
                value="{{ old('url') ?? ($website->url ?? null) }}"
                type="text"
                name="url"
                id="url"
                class="input secondary rounded-none rounded-r-md"
                placeholder="www.example.com">
        </div>
        <x-forms.errors name="url"></x-forms.errors>
    </div>

    <div>
        <label for="environment" class="block text-sm font-medium text-primary">
            {{ __('Environment') }}
        </label>
        <div class="mt-1">
            <textarea
                id="environment"
                name="environment"
                rows="3"
                class="input secondary rounded"
                placeholder="APP_ENV=production....">{{ old('environment') ?? ($website->environment ?? null) }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Your environment file contents') }}
        </p>
        <x-forms.errors name="environment"></x-forms.errors>
    </div>

    <div class="rounded-lg border border-primary p-4">
        <div class="flex items-start gap-3">
            <input type="hidden" name="health_check_enabled" value="0">
            <input
                id="health_check_enabled"
                name="health_check_enabled"
                type="checkbox"
                value="1"
                class="mt-1 rounded border-primary"
                @checked((bool) old('health_check_enabled', $website->health_check_enabled ?? false))
            >
            <div>
                <label for="health_check_enabled" class="block text-sm font-medium text-primary">
                    {{ __('Verify website health after deployment') }}
                </label>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('A failed check restores the previous release symlink. Database migrations are not rolled back.') }}
                </p>
            </div>
        </div>

        <div class="mt-4">
            <label for="health_check_path" class="block text-sm font-medium text-primary">
                {{ __('Health check path') }}
            </label>
            <div class="mt-1 flex rounded-md shadow-sm">
                <span class="inline-flex items-center rounded-l-md border border-r-0 border-primary bg-tertiary px-3 text-sm text-primary">
                    http://{{ old('url', $website->url ?? __('website')) }}
                </span>
                <input
                    value="{{ old('health_check_path', $website->health_check_path ?? '/') }}"
                    type="text"
                    name="health_check_path"
                    id="health_check_path"
                    class="input secondary rounded-none rounded-r-md"
                    placeholder="/health"
                >
            </div>
            <p class="mt-2 text-sm text-secondary">
                {{ __('Redirects are followed; the final response must be successful.') }}
            </p>
            <x-forms.errors name="health_check_enabled"></x-forms.errors>
            <x-forms.errors name="health_check_path"></x-forms.errors>
        </div>
    </div>

    <div>
        <label for="description" class="block text-sm font-medium text-primary">
            {{ __('Description') }}
        </label>
        <div class="mt-1">
            <textarea
                id="description"
                name="description"
                rows="3"
                class="input secondary rounded"
                placeholder="My website">{{ old('description') ?? ($website->description ?? null) }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Brief description of your website') }}
        </p>
        <x-forms.errors name="description"></x-forms.errors>
    </div>
</div>
