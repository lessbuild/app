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
        <div class="w-4/6 hidden lg:flex items-center justify-center relative">
            <div class="absolute h-full w-full opacity-30 bg-contain bg-repeat"
                style="background-image: url('http://gopayee.test/assets/images/patterns/pattern_one.png"
            >
            </div>
        </div>
    </div>

</x-core::layouts.core>
