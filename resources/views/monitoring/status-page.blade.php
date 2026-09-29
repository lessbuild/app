@php($project = $overview->project)
@php($tone = fn (string $state): string => match ($state) { 'operational' => 'success', 'major_outage' => 'danger', 'maintenance' => 'info', default => 'warning' })

<x-signal.layouts.project :overview="$overview" :title="$page->name" :description="$page->published ? $page->publicUrl() : __('Draft: only your team can see this page.')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <x-signal.ui.badge :tone="$page->published ? 'success' : 'neutral'">{{ $page->published ? __('Published') : __('Draft') }}</x-signal.ui.badge>
            <x-signal.ui.badge :tone="$tone($report['overall'])">{{ $report['overallLabel'] }}</x-signal.ui.badge>
            <span class="text-sm text-muted">{{ trans_choice(':count subscriber|:count subscribers', $subscribers, ['count' => $subscribers]) }}</span>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($page->published)
                <x-signal.ui.button :href="$page->publicUrl()" variant="secondary" target="_blank" rel="noopener">{{ __('Open public page') }}</x-signal.ui.button>
            @endif
            @if ($canManage)
                <x-signal.ui.button :href="route('monitoring.status-pages.edit', [$project, $page->id])" variant="secondary">{{ __('Edit') }}</x-signal.ui.button>
            @endif
        </div>
    </div>

    <x-signal.ui.card class="overflow-hidden">
        <div class="border-b border-line px-5 py-4"><h2 class="font-extrabold text-ink">{{ __('Components') }}</h2></div>
        @if ($report['components'] === [])
            <p class="px-5 py-4 text-sm text-muted">{{ __('No components yet. Edit the page to choose monitors.') }}</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($report['components'] as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div>
                            <p class="font-bold text-ink">{{ $row['name'] }}</p>
                            <p class="text-xs text-muted">{{ $row['type'] }}@if ($row['history'] && $row['history']['uptime'] !== null) · {{ __(':uptime% over 30 days', ['uptime' => number_format($row['history']['uptime'], 2)]) }}@endif</p>
                        </div>
                        <x-signal.ui.badge :tone="$tone($row['state'])">{{ $row['stateLabel'] }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-signal.ui.card>

    @if ($page->published)
        <x-signal.ui.settings-section id="share" :title="__('Share and embed')" :description="__('Put the page’s state in a README, a footer or your app. Badges and the widget update within a minute.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <div class="flex flex-wrap items-center gap-3">
                    <img src="{{ route('status.badge', $page->slug) }}" alt="{{ __(':page status badge', ['page' => $page->name]) }}" height="20">
                    <img src="{{ route('status.badge', [$page->slug, 'show' => 'uptime']) }}" alt="{{ __(':page uptime badge', ['page' => $page->name]) }}" height="20">
                </div>
                <div class="grid gap-1">
                    <p class="text-xs font-bold text-muted">{{ __('Status badge (Markdown)') }}</p>
                    <x-signal.ui.code-block :code="'[!['.$page->name.' status]('.route('status.badge', $page->slug).')]('.$page->publicUrl().')'" class="break-all whitespace-pre-wrap" />
                </div>
                <div class="grid gap-1">
                    <p class="text-xs font-bold text-muted">{{ __('Uptime badge (Markdown)') }}</p>
                    <x-signal.ui.code-block :code="'[!['.$page->name.' uptime]('.route('status.badge', [$page->slug, 'show' => 'uptime']).')]('.$page->publicUrl().')'" class="break-all whitespace-pre-wrap" />
                </div>
                <div class="grid gap-1">
                    <p class="text-xs font-bold text-muted">{{ __('Widget for your site (HTML)') }}</p>
                    <x-signal.ui.code-block :code="'<iframe src=\''.route('status.embed', $page->slug).'\' title=\''.e($page->name).' status\' width=\'260\' height=\'40\' style=\'border:0\' loading=\'lazy\'></iframe>'" class="break-all whitespace-pre-wrap" />
                </div>
            </div>
        </x-signal.ui.settings-section>
    @endif

    @if ($canManage)
        <x-signal.ui.settings-section id="custom-domain" :title="__('Custom domain')" :description="__('Serve this page on a domain of your own, such as status.example.com. HTTPS is set up for you.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <form method="POST" action="{{ route('monitoring.status-pages.domain', [$project, $page->id]) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PUT')
                    <div class="min-w-64 flex-1"><x-signal.ui.input-field name="custom_domain" :label="__('Domain')" :value="$page->custom_domain" placeholder="status.example.com" autocomplete="off" /></div>
                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Save') }}</x-signal.ui.button>
                </form>
                @if ($page->custom_domain !== null)
                    @if ($page->custom_domain_verified_at !== null)
                        <p class="flex flex-wrap items-center gap-2 text-sm"><x-signal.ui.badge tone="success">{{ __('Verified') }}</x-signal.ui.badge>
                            @if ($page->published)
                                <span>{{ __('Served at') }} <a href="{{ $page->publicUrl() }}" class="font-bold text-primary underline" target="_blank" rel="noopener">{{ $page->custom_domain }}</a></span>
                            @else
                                <span>{{ __('Publish the page to serve it there.') }}</span>
                            @endif
                        </p>
                    @else
                        <x-signal.ui.alert tone="info">{{ __('Add these two DNS records at your DNS provider, then check them. The first proves the domain is yours; the second sends its visitors here.') }}</x-signal.ui.alert>
                    @endif
                    <x-signal.ui.table :caption="__('DNS records')">
                        <thead><tr><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Value') }}</th></tr></thead>
                        <tbody>
                            <tr><td>TXT</td><td class="font-mono text-xs break-all">{{ $page->domainRecordName() }}</td><td class="font-mono text-xs break-all">{{ $page->domainRecordValue() }}</td></tr>
                            <tr><td>CNAME</td><td class="font-mono text-xs break-all">{{ $page->custom_domain }}</td><td class="font-mono text-xs break-all">{{ config('monitoring.status_pages.domain_target') }}</td></tr>
                        </tbody>
                    </x-signal.ui.table>
                    @if ($page->custom_domain_verified_at === null)
                        <form method="POST" action="{{ route('monitoring.status-pages.domain.verify', [$project, $page->id]) }}">
                            @csrf
                            <x-signal.ui.button type="submit" variant="primary">{{ __('Check DNS') }}</x-signal.ui.button>
                        </form>
                    @endif
                    <p class="text-xs text-muted">{{ __('For a bare domain (example.com) that can’t have a CNAME, point an A record at the same address as :target.', ['target' => config('monitoring.status_pages.domain_target')]) }}</p>
                @endif
            </div>
        </x-signal.ui.settings-section>
    @endif

    @if ($canManage)
        <x-slot:actions>
            <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'post-status-update'])" variant="primary" data-modal-trigger="post-status-update">{{ __('Post an update') }}</x-signal.ui.button>
        </x-slot:actions>
        <x-signal.overlays.form-modal id="post-status-update" :title="__('Post an update')" :description="$page->published ? __('Shown on the page at once and emailed to confirmed subscribers.') : __('Saved now; shown and emailed once the page is published.')" :action="route('monitoring.status-pages.updates.store', [$project, $page->id])" :submit="__('Post update')" form-class="grid items-start gap-4 sm:grid-cols-2">
            @include('monitoring._status-update-fields', ['update' => null])
        </x-signal.overlays.form-modal>
    @endif

    <x-signal.ui.card class="overflow-hidden">
        <div class="border-b border-line px-5 py-4"><h2 class="font-extrabold text-ink">{{ __('Updates') }}</h2></div>
        @if ($updates->isEmpty())
            <p class="px-5 py-4 text-sm text-muted">{{ __('Nothing posted yet.') }}</p>
        @else
            <ul class="divide-y divide-line" aria-label="{{ __('Updates') }}">
                @foreach ($updates as $update)
                    <li class="grid gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-extrabold text-ink">{{ $update->title }}
                                <x-signal.ui.badge :tone="$update->isClosed() ? 'neutral' : ($update->kind === 'maintenance' ? 'info' : 'warning')">{{ $update->statusLabel() }}</x-signal.ui.badge>
                            </p>
                            <p class="mt-0.5 text-xs text-muted">{{ $update->kind === 'maintenance' ? __('Maintenance') : __('Incident · :impact impact', ['impact' => __(ucfirst($update->severity))]) }} · {{ $update->starts_at->format('Y-m-d H:i') }}@if ($update->ends_at) – {{ $update->ends_at->format('Y-m-d H:i') }}@endif UTC</p>
                            <p class="mt-2 whitespace-pre-line text-sm text-muted">{{ $update->message }}</p>
                        </div>
                        @if ($canManage)
                            <details>
                                <summary class="cursor-pointer text-sm font-bold text-primary">{{ __('Change') }}</summary>
                                <form method="POST" action="{{ route('monitoring.status-pages.updates.update', [$project, $page->id, $update->id]) }}" class="mt-3 grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-2">
                                    @csrf
                                    @method('PUT')
                                    @include('monitoring._status-update-fields', ['update' => $update])
                                    <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save and notify') }}</x-signal.ui.button></div>
                                </form>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-signal.ui.card>
</x-signal.layouts.project>
