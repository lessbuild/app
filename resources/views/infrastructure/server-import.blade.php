@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Import a server')" :description="__('We connect over SSH, look around without changing anything, and show what we found before you confirm.')">
    @foreach (['plan', 'connection'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    <x-signal.ui.card>
        <form method="POST" action="{{ route('infrastructure.imports.store', $project) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
            @csrf
            @include('infrastructure._server-import-fields')
            <div class="flex flex-wrap gap-3 sm:col-span-2">
                <x-signal.ui.button type="submit" variant="primary">{{ __('Inspect server') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('infrastructure.servers', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
            </div>
        </form>
    </x-signal.ui.card>
</x-signal.layouts.project>
