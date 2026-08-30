<div class="px-4 py-5 bg-primary space-y-6 sm:p-6">

    <div class="col-span-3 sm:col-span-2">
        <label for="website_id" class="block text-sm font-medium text-primary">
            {{ __('Website') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select name="website_id" class="input secondary rounded">
                @foreach($websites as $website)
                    <option value="{{ $website->id }}"
                        @selected(old('website_id') == $website->id || ($website->website_id ?? null) == $website->id)
                    >
                        {{ $website->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="website_id"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="provider_id" class="block text-sm font-medium text-primary">
            {{ __('Provider') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select name="provider_id" class="input secondary rounded">
                @foreach($providers as $provider)
                    <option
                        value="{{ $provider->id }}"
                        @selected(old('provider_id') == $provider->id || ($repository->provider_id ?? null) == $provider->id)
                    >
                        {{ $provider->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="provider_id"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="name" class="block text-sm font-medium text-primary">
            {{ __('Repository Name') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <input
                value="{{ old('name') ?? ($repository->name ?? null) }}"
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
            {{ __('Repository URL') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-primary bg-tertiary text-primary text-sm">
                http://
            </span>
            <input
                value="{{ old('url') ?? ($repository->url ?? null) }}"
                type="text"
                name="url"
                id="url"
                class="input secondary rounded-none rounded-r-md"
                placeholder="github.com/user/repo.git">
        </div>
        <x-forms.errors name="url"></x-forms.errors>
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
                placeholder="you@example.com">{{ old('description') ?? ($repository->description ?? null) }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Brief description of your repository') }}
        </p>
        <x-forms.errors name="description"></x-forms.errors>
    </div>
</div>
