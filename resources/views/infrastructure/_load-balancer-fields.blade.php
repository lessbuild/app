@php($prefix = $balancer ? "balancer-{$balancer->id}-" : 'balancer-')
<x-signal.ui.input-field :id="$prefix.'hostname'" name="hostname" :label="__('Hostname')" :value="old('hostname', $balancer?->hostname)" placeholder="shop.example.com" maxlength="253" required />
<x-signal.ui.input-field :id="$prefix.'health'" name="health_path" :label="__('Health check path')" :value="old('health_path', $balancer?->health_path ?? '/up')" maxlength="255" required />
<x-signal.ui.select-field :id="$prefix.'website'" name="website_id" :label="__('Website (optional)')">
    <option value="">{{ __('None') }}</option>
    @foreach ($websites as $website)
        <option value="{{ $website->id }}" @selected((int) old('website_id', $balancer?->website_id) === $website->id)>{{ $website->name }}</option>
    @endforeach
</x-signal.ui.select-field>
