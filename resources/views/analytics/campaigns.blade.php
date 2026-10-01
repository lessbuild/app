@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Campaigns')" :description="__('Tag the links you share with UTM parameters, then see which campaigns bring visitors who convert.')">
    @if ($site === null)
        <x-signal.ui.empty-state icon="view-grid" :title="__('Add a site first')" :description="__('Campaign results belong to a site.')" />
    @else
        @include('analytics._site-picker')

        <x-signal.ui.settings-section id="builder" :title="__('Campaign link builder')" :description="__('Source is required by most tools; campaign names what you’re promoting. Values show up exactly as typed, so keep them consistent (e.g. newsletter, not Newsletter).')">
            <form method="GET" action="{{ route('analytics.campaigns', $project) }}" class="grid items-start gap-4 p-4 sm:grid-cols-2 sm:p-6">
                <input type="hidden" name="site" value="{{ $site->id }}">
                <div class="sm:col-span-2"><x-signal.ui.input-field name="url" type="url" :label="__('Page address')" :value="$url" maxlength="2048" required :restore="false" /></div>
                @foreach (\App\Support\CampaignLink::PARAMETERS as $key => $label)
                    <x-signal.ui.input-field :name="$key" :label="__($label).' ('.$key.')'" :value="$parameters[$key]" maxlength="200" :restore="false"
                        :placeholder="match ($key) { 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn-launch', 'utm_term' => __('paid keyword'), default => __('which link or ad') }" />
                @endforeach
                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Build link') }}</x-signal.ui.button></div>
            </form>
            @if ($invalid)
                <div class="px-4 pb-4 sm:px-6 sm:pb-6"><x-signal.ui.alert tone="danger">{{ __('Enter a full http:// or https:// address.') }}</x-signal.ui.alert></div>
            @elseif ($link)
                <div class="grid gap-2 px-4 pb-4 sm:px-6 sm:pb-6">
                    <p class="text-xs font-bold text-muted">{{ __('Your link') }}</p>
                    <x-signal.ui.code-block :code="$link" class="break-all whitespace-pre-wrap" />
                </div>
            @endif
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Campaign results')" :description="__('Visits that arrived through a tagged link in the last 30 days.')">
            @if ($results === [])
                <p class="p-4 text-sm text-muted sm:p-6">{{ __('No tagged visits yet. Share a link from the builder above.') }}</p>
            @else
                <x-signal.ui.table :caption="__('Campaign results')" :framed="false">
                    <x-slot:head><tr><th scope="col">{{ __('Campaign') }}</th><th scope="col">{{ __('Source / medium') }}</th><th scope="col" class="text-right">{{ __('Visits') }}</th><th scope="col" class="text-right">{{ __('Pageviews') }}</th><th scope="col" class="text-right">{{ __('Converted') }}</th><th scope="col" class="text-right">{{ __('Revenue') }}</th>@if ($spend->isNotEmpty())<th scope="col" class="text-right">{{ __('Cost') }}</th><th scope="col" class="text-right">{{ __('Cost per conversion') }}</th><th scope="col" class="text-right"><abbr title="{{ __('Return on ad spend: revenue divided by cost') }}">{{ __('ROAS') }}</abbr></th>@endif</tr></x-slot:head>
                    @foreach ($results as $row)
                        <tr>
                            <td><a class="font-bold text-primary hover:underline" href="{{ route('analytics.overview', [$project, 'site' => $site->id, 'days' => 30, 'campaign' => $row['campaign']]) }}">{{ $row['campaign'] }}</a></td>
                            <td class="text-muted">{{ $row['source'] ?? '—' }}{{ $row['medium'] ? ' / '.$row['medium'] : '' }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['visits']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['pageviews']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['converted']) }} <span class="text-muted">({{ $row['rate'] }}%)</span></td>
                            <td class="text-right tabular-nums">{{ $row['revenue'] ?? '—' }}</td>
                            @if ($spend->isNotEmpty())
                                <td class="text-right tabular-nums">{{ $row['cost'] ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ $row['cost_per_conversion'] ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ $row['roas'] === null ? '—' : number_format($row['roas'], 2).'×' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </x-signal.ui.table>
            @endif
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section id="ad-spend" :title="__('Ad spend')" :description="__('Import what your ads cost to see cost per conversion and return on ad spend beside each campaign. Export a daily campaign report from Google Ads, Meta, LinkedIn or any ad platform as CSV, with date, campaign and cost columns. Campaign and source must match the utm_campaign and utm_source on your ads’ links.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @forelse ($spend as $row)
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <span><span class="font-bold text-ink">{{ $row->source }}</span> <span class="text-muted">· {{ trans_choice(':count campaign day|:count campaign days', (int) $row->days, ['count' => number_format((int) $row->days)]) }} · {{ substr((string) $row->first_date, 0, 10) }} – {{ substr((string) $row->last_date, 0, 10) }}</span></span>
                        @if ($canManage)
                            <form method="POST" action="{{ route('analytics.ad-spend.clear', [$project, $site->id]) }}">
                                @csrf @method('DELETE')
                                <input type="hidden" name="source" value="{{ $row->source }}">
                                <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No ad spend imported yet.') }}</p>
                @endforelse
                @foreach ($adAccounts as $adAccount)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3 text-sm">
                        <span>
                            <span class="font-bold text-ink">{{ $adAccount->name }}</span>
                            <span class="text-muted">· {{ $adAccount->platformName() }} · {{ __('filed under :source', ['source' => $adAccount->source]) }}
                                · {{ $adAccount->synced_at ? __('read :time', ['time' => $adAccount->synced_at->diffForHumans()]) : __('not read yet') }}</span>
                            @if ($adAccount->error)<span class="block text-xs text-danger">{{ $adAccount->error }}</span>@endif
                        </span>
                        @if ($canManage)
                            <span class="flex gap-2">
                                <form method="POST" action="{{ route('analytics.sites.ads.sync', [$project, $site->id, $adAccount->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Read now') }}</x-signal.ui.button></form>
                                <form method="POST" action="{{ route('analytics.sites.ads.disconnect', [$project, $site->id, $adAccount->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Disconnect') }}</x-signal.ui.button></form>
                            </span>
                        @endif
                    </div>
                @endforeach
                @error('ads')<x-signal.ui.alert tone="danger">{{ $message }}</x-signal.ui.alert>@enderror
                @if ($canManage && $adChoice)
                    <form method="POST" action="{{ route('analytics.sites.ads.choose', [$project, $site->id]) }}" class="flex flex-wrap items-end gap-3 border-t border-line pt-3">
                        @csrf
                        <x-signal.ui.select-field name="account_id" :label="__('Ad account')">
                            @foreach ($adChoice['accounts'] as $choice)
                                <option value="{{ $choice['id'] }}">{{ $choice['name'] }}{{ $choice['currency'] ? ' · '.$choice['currency'] : '' }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Connect this account') }}</x-signal.ui.button>
                    </form>
                @endif
                @if ($canManage && $adPlatforms !== [])
                    <div class="flex flex-wrap items-center gap-3 border-t border-line pt-3">
                        <span class="text-sm text-muted">{{ __('Read spend daily, straight from the platform:') }}</span>
                        @foreach ($adPlatforms as $platform)
                            <form method="POST" action="{{ route('analytics.sites.ads.connect', [$project, $site->id, $platform]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Connect :platform', ['platform' => \App\Models\AnalyticsAdAccount::PLATFORMS[$platform]['name']]) }}</x-signal.ui.button></form>
                        @endforeach
                    </div>
                @endif
                @if ($canManage)
                    <form method="POST" action="{{ route('analytics.ad-spend.import', [$project, $site->id]) }}" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-3 sm:items-end">
                        @csrf
                        <x-signal.ui.input-field name="file" type="file" accept=".csv,.tsv,text/csv" :label="__('CSV file')" required />
                        <x-signal.ui.input-field name="source" :label="__('Source (when the file has none)')" maxlength="100" required value="google" />
                        <x-signal.ui.input-field name="currency" :label="__('Currency (when the file has none)')" maxlength="3" required value="USD" />
                        <div class="sm:col-span-3"><x-signal.ui.button type="submit" variant="secondary">{{ __('Import') }}</x-signal.ui.button></div>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
