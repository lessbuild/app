@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Firewall')" :description="__('Cloudflare’s protection for the zones behind this project’s domains. Per-domain country and address blocks and rate limits are on each website’s Domains tab.')">
    @unless ($included)
        <x-signal.ui.alert tone="info">{{ __('Firewall and bot controls come with the Team Security plan.') }} <a class="ui-link" href="{{ route('account.billing', ['tab' => 'security']) }}">{{ __('See plans') }}</a></x-signal.ui.alert>
    @endunless

    @forelse ($zones as $row)
        @php($zone = $row['zone'])
        <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="zone-{{ $zone->zone_id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="zone-{{ $zone->zone_id }}" class="text-lg font-extrabold text-ink">{{ $zone->zone_name ?? __('Cloudflare zone') }}</h2>
                    <p class="text-sm text-muted">{{ implode(', ', $row['domains']) }}</p>
                </div>
                @if ($zone->under_attack)<x-signal.ui.badge tone="danger">{{ __('Under attack mode') }}</x-signal.ui.badge>@endif
            </div>
            @if ($zone->last_error)<x-signal.ui.alert tone="danger">{{ $zone->last_error }}</x-signal.ui.alert>@endif
            <form method="POST" action="{{ route('security.firewall.update', [$project, $zone->zone_id]) }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                @csrf @method('PUT')
                <fieldset class="contents" @disabled(! $canManage || ! $included)>
                    <x-signal.ui.select-field :id="'level-'.$zone->zone_id" name="security_level" :label="__('Security level')" :description="__('How suspicious a visitor must look before Cloudflare asks them to prove they’re human.')">
                        @foreach (\App\Models\SecurityZone::LEVELS as $value => $label)
                            <option value="{{ $value }}" @selected($zone->security_level === $value)>{{ __($label) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.checkbox :id="'bots-'.$zone->zone_id" name="bot_fight_mode" :checked="$zone->bot_fight_mode" :description="__('Challenge traffic from known bots and scrapers.')">{{ __('Bot fight mode') }}</x-signal.ui.checkbox>
                    <x-signal.ui.checkbox :id="'attack-'.$zone->zone_id" name="under_attack" :checked="$zone->under_attack" :description="__('Every visitor gets a short check before reaching the site. Use it only during an attack.')">{{ __('Under attack mode') }}</x-signal.ui.checkbox>
                    @if ($canManage && $included)<div class="sm:col-span-3"><x-signal.ui.button type="submit" variant="primary">{{ __('Save') }}</x-signal.ui.button></div>@endif
                </fieldset>
            </form>
        </x-signal.ui.card>
    @empty
        <x-signal.ui.empty-state icon="globe" :title="__('No Cloudflare zones yet')" :description="__('Connect Cloudflare as a DNS provider and add a website domain in one of its zones; the zone shows up here.')" />
    @endforelse
</x-signal.layouts.project>
