<x-signal.layouts.admin :title="__('Overview')" :description="__('Operate the platform. Everything you change here, and every customer you look up, goes in the trail below.')">
    <x-signal.ui.settings-section :title="__('Admin trail')" :description="__('The latest 50 entries: admin rights granted or revoked, and what admins did here.')">
        @if ($events->isEmpty())
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('Nothing yet.') }}</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($events as $event)
                    <li class="px-4 py-3 text-sm sm:px-6">
                        <p>{{ $event->description }}</p>
                        <p class="text-xs text-muted">{{ $event->actor->name ?? __('Command line') }} · {{ $event->action }} · {{ $event->created_at->diffForHumans() }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-signal.ui.settings-section>
</x-signal.layouts.admin>
