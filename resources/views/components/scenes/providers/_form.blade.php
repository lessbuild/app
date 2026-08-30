<div class="px-4 py-5 bg-primary space-y-6 sm:p-6">

    <div class="col-span-3 sm:col-span-2">
        <label for="provider" class="block text-sm font-medium text-primary">
            {{ __('Provider') }}
        </label>
        <div
            class="mt-1 grid grid-cols-2 gap-4 rounded-md shadow-sm"
            role="radiogroup"
            aria-label="{{ __('Provider') }}"
            x-data="{ provider: {{ Illuminate\Support\Js::from(old('provider', $provider->provider ?? null)) }} }"
        >
            <input type="hidden" name="provider" :value="provider">
            <div
                class="bg-secondary border border-secondary rounded p-2"
                :class="{ 'bg-tertiary': provider == 'digitalocean' }"
                @click="provider = 'digitalocean'"
                @keydown.enter="provider = 'digitalocean'"
                @keydown.space.prevent="provider = 'digitalocean'"
                role="radio"
                tabindex="0"
                :aria-checked="provider == 'digitalocean'">
                <svg class="w-full h-full text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#digital-ocean"></use>
                </svg>
                <span class="sr-only">{{ __('DigitalOcean') }}</span>
            </div>

            <div
                class="bg-secondary border border-secondary rounded p-2"
                :class="{ 'bg-tertiary': provider == 'github' }"
                @click="provider = 'github'"
                @keydown.enter="provider = 'github'"
                @keydown.space.prevent="provider = 'github'"
                role="radio"
                tabindex="0"
                :aria-checked="provider == 'github'">
                <svg class="w-full h-full text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#github"></use>
                </svg>
                <span class="sr-only">{{ __('GitHub') }}</span>
            </div>

            <div
                class="bg-secondary border border-secondary rounded p-2"
                :class="{ 'bg-tertiary': provider == 'gitlab' }"
                @click="provider = 'gitlab'"
                @keydown.enter="provider = 'gitlab'"
                @keydown.space.prevent="provider = 'gitlab'"
                role="radio"
                tabindex="0"
                :aria-checked="provider == 'gitlab'">
                <svg class="w-full h-full text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#gitlab"></use>
                </svg>
                <span class="sr-only">{{ __('GitLab') }}</span>
            </div>

            <div
                class="bg-secondary border border-secondary rounded p-2"
                :class="{ 'bg-tertiary': provider == 'bitbucket' }"
                @click="provider = 'bitbucket'"
                @keydown.enter="provider = 'bitbucket'"
                @keydown.space.prevent="provider = 'bitbucket'"
                role="radio"
                tabindex="0"
                :aria-checked="provider == 'bitbucket'">
                <svg class="w-full h-full text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#bitbucket"></use>
                </svg>
                <span class="sr-only">{{ __('Bitbucket') }}</span>
            </div>

        </div>
        <x-forms.errors name="provider"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="token" class="block text-sm font-medium text-primary">
            {{ __('Provider Token') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <input
                value="{{ old('token') }}"
                type="password"
                name="token"
                id="token"
                autocomplete="off"
                @if (! isset($provider)) required @endif
                class="input secondary rounded"
                placeholder="************">
        </div>
        <x-forms.errors name="token"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="name" class="block text-sm font-medium text-primary">
            {{ __('Provider Name') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <input
                value="{{ old('name') ?? ($provider->name ?? null) }}"
                type="text"
                name="name"
                id="name"
                class="input secondary rounded"
                placeholder="Example: Source control access token">
        </div>
        <x-forms.errors name="name"></x-forms.errors>
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
                placeholder="{{ __('Example: To manage one website') }}">{{ old('description') ?? ($provider->description ?? null) }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Brief description of the provider token') }}
        </p>
        <x-forms.errors name="description"></x-forms.errors>
    </div>
</div>
