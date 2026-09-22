<x-layouts.core :title="$title">
    <div class="ui-auth-shell min-h-screen bg-page lg:grid lg:grid-cols-[minmax(24rem,0.82fr)_minmax(28rem,1.18fr)]">
        <main id="main-content" tabindex="-1" class="ui-auth-main flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 lg:px-10">
            <div class="w-full max-w-lg">
                <a href="{{ url('/') }}" data-auth-brand class="ui-auth-brand inline-flex min-h-[2.5rem] items-center text-lg font-black uppercase tracking-tight text-ink">
                    {{ config('app.name') }}
                </a>

                <x-ui.card class="ui-auth-panel mt-6 p-6 sm:p-8">
                    <header>
                        <h1 class="text-2xl font-black tracking-tight text-ink">{{ $title }}</h1>
                        <div class="mt-2 leading-6 text-muted">{{ $description }}</div>
                    </header>

                    @if ($errors->any())
                        <x-ui.alert class="mt-5" tone="danger" role="alert">
                            <p class="font-bold">{{ __('Whoops! Something went wrong.') }}</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif

                    <div class="mt-6">
                        {{ $slot }}
                    </div>
                </x-ui.card>
            </div>
        </main>

        <aside class="ui-auth-aside relative hidden items-center justify-center overflow-hidden lg:flex" aria-label="{{ __(':app overview', ['app' => config('app.name')]) }}">
            <div
                class="ui-auth-aside__pattern absolute inset-0 opacity-30"
                style="background-image: radial-gradient(circle, rgb(148 163 184) 1px, transparent 1px); background-size: 24px 24px;"
            ></div>
            <div class="relative max-w-md px-8 text-center text-slate-100">
                <svg class="mx-auto h-16 w-16 stroke-2 text-[var(--ui-primary)]">
                    <use xlink:href="/assets/images/icons.svg#cloud-upload"></use>
                </svg>
                <p class="mt-6 text-2xl font-semibold">{{ __('Deploy with confidence') }}</p>
                <p class="mt-3 text-sm text-slate-300">
                    {{ __('Provision infrastructure, release applications, and review operational history from one control panel.') }}
                </p>
            </div>
        </aside>
    </div>

</x-layouts.core>
