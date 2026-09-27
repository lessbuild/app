@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Websites')" :description="__('Sites on your app servers: a Caddy site with HTTPS, a MySQL database and a .env file each.')">
    @if ($canManage)
        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($limit !== null)
                <span class="text-sm text-muted">{{ __(':used of :limit websites on your plan', ['used' => $websites->count(), 'limit' => $limit]) }}</span>
            @endif
            <x-signal.ui.button :href="route('infrastructure.websites.create', [$project, 'import' => 1])" variant="secondary">{{ __('Import a website') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('infrastructure.websites.create', $project)" variant="primary">{{ __('Create a website') }}</x-signal.ui.button>
        </div>
    @endif

    @if ($websites->isEmpty())
        <x-signal.ui.empty-state icon="globe" :title="__('No websites yet')" :description="__('Create one on an app server, or import an application already under /var/www.')" />
    @else
        <x-signal.ui.table :caption="__('Websites')">
            <x-slot:head><tr><th scope="col">{{ __('Website') }}</th><th scope="col">{{ __('Domain') }}</th><th scope="col">{{ __('Server') }}</th><th scope="col">{{ __('Status') }}</th></tr></x-slot:head>
            @foreach ($websites as $website)
                <tr>
                    <td><a href="{{ route('infrastructure.websites.show', [$project, $website->id]) }}" class="font-bold text-primary hover:underline">{{ $website->name }}</a></td>
                    <td class="font-mono text-xs">{{ $website->url }}</td>
                    <td class="text-muted">{{ $website->server?->label() ?? '—' }}</td>
                    <td>@include('infrastructure._website-status', ['website' => $website])</td>
                </tr>
            @endforeach
        </x-signal.ui.table>
    @endif
</x-signal.layouts.project>
