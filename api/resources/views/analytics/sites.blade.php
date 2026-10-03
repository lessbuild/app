<x-signal.layouts.project :overview="$overview" :title="__('Analytics sites')" :description="__('Each site is a website whose visits this project records.')">
    @if ($sites === [])
        <x-signal.ui.empty-state icon="view-grid" :title="__('Add your first site')" :description="__('You’ll get a one-line snippet to paste into your pages. No cookies, no personal data.')">
            @if ($canManage)
                <x-slot:action><x-signal.ui.button :href="route('analytics.sites', [$overview->project, 'dialog' => 'add-site'])" variant="primary" data-modal-trigger="add-site">{{ __('Add a site') }}</x-signal.ui.button></x-slot:action>
            @endif
        </x-signal.ui.empty-state>
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Sites') }}">
                @foreach ($sites as $site)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('analytics.sites.show', [$overview->project, $site->id]) }}" class="font-extrabold text-ink hover:underline">{{ $site->name }}</a>
                            <p class="mt-0.5 break-all text-xs text-muted">{{ implode(', ', $site->domains) }} · {{ $site->last_event_at ? __('Last visit :time', ['time' => $site->last_event_at->diffForHumans()]) : __('No visits yet') }}</p>
                        </div>
                        <x-signal.ui.badge :tone="$site->isCollectionAvailable() ? 'success' : 'warning'">{{ $site->isVerified() ? ($site->isCollectionAvailable() ? __('Collecting') : __('Paused')) : __('Not verified') }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif

    @if ($canManage)
        <x-slot:actions>
            <x-signal.ui.button :href="route('analytics.sites', [$overview->project, 'dialog' => 'add-site'])" variant="primary" data-modal-trigger="add-site">{{ __('Add a site') }}</x-signal.ui.button>
        </x-slot:actions>
        <x-signal.overlays.modal id="add-site" :title="__('Add a site')" :description="__('A hostname must be a verified domain of this project (or a subdomain of one) before the site collects.')">
            <form method="POST" action="{{ route('analytics.sites.store', $overview->project) }}" class="grid gap-5">
                @csrf
                <input type="hidden" name="_modal" value="add-site">
                @include('analytics._site-fields', ['site' => null, 'timezones' => \DateTimeZone::listIdentifiers()])
                <div class="flex justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Add site') }}</x-signal.ui.button></div>
            </form>
        </x-signal.overlays.modal>
    @endif
</x-signal.layouts.project>
