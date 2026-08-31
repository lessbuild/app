<x-layouts.core>
    <div class="min-h-screen bg-secondary flex">
        <div class="border border-primary bg-primary w-full lg:w-2/6 flex flex-col justify-center py-6 px-4 sm:px-6 lg:flex-none lg:px-10">
            <div>
                <a href="/" class="flex items-center">
                    <span class="font-bold text-3xl text-primary">{{ config('app.name') }}</span>
                </a>
                <h2 class="mt-6 text-xl font-extrabold text-primary">
                    {{ $title }}
                </h2>
                <p class="mt-1 text-secondary">
                    {{ $description }}
                </p>

                @if ($errors->any())
                    <div {{ $attributes }} class="mt-5">
                        <div class="font-medium text-ternary">
                            {{ __('Whoops! Something went wrong.') }}
                        </div>

                        <ul class="mt-3 list-disc list-inside text-sm text-ternary">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            <div class="mt-2">
                {{ $slot }}
            </div>
        </div>
        <div class="relative hidden w-4/6 items-center justify-center overflow-hidden bg-slate-900 lg:flex">
            <div
                class="absolute inset-0 opacity-30"
                style="background-image: radial-gradient(circle, rgb(148 163 184) 1px, transparent 1px); background-size: 24px 24px;"
            ></div>
            <div class="relative max-w-md px-8 text-center text-slate-100">
                <svg class="mx-auto h-16 w-16 stroke-2 text-blue-400">
                    <use xlink:href="/assets/images/icons.svg#cloud-upload"></use>
                </svg>
                <p class="mt-6 text-2xl font-semibold">{{ __('Deploy with confidence') }}</p>
                <p class="mt-3 text-sm text-slate-300">
                    {{ __('Provision infrastructure, release applications, and review operational history from one control panel.') }}
                </p>
            </div>
        </div>
    </div>

</x-layouts.core>
