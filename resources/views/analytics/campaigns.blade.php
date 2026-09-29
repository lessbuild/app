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
                    <x-slot:head><tr><th scope="col">{{ __('Campaign') }}</th><th scope="col">{{ __('Source / medium') }}</th><th scope="col" class="text-right">{{ __('Visits') }}</th><th scope="col" class="text-right">{{ __('Pageviews') }}</th><th scope="col" class="text-right">{{ __('Converted') }}</th></tr></x-slot:head>
                    @foreach ($results as $row)
                        <tr>
                            <td><a class="font-bold text-primary hover:underline" href="{{ route('analytics.overview', [$project, 'site' => $site->id, 'days' => 30, 'campaign' => $row['campaign']]) }}">{{ $row['campaign'] }}</a></td>
                            <td class="text-muted">{{ $row['source'] ?? '—' }}{{ $row['medium'] ? ' / '.$row['medium'] : '' }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['visits']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['pageviews']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['converted']) }} <span class="text-muted">({{ $row['rate'] }}%)</span></td>
                        </tr>
                    @endforeach
                </x-signal.ui.table>
            @endif
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
