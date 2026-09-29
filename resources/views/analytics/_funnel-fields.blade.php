{{-- A funnel's name and up to six steps; $funnel is null for a new one. Empty rows are skipped. --}}
@php($steps = $funnel?->steps ?? [['kind' => 'pageview', 'match' => 'exact', 'value' => '/'], ['kind' => 'pageview', 'match' => 'exact', 'value' => '']])
<div class="sm:col-span-3"><x-signal.ui.input-field :id="$prefix.'-name'" name="name" :label="__('Name')" :value="$funnel?->name" placeholder="Checkout" maxlength="120" required /></div>
@for ($i = 0; $i < 6; $i++)
    @php($step = $steps[$i] ?? ['kind' => 'pageview', 'match' => 'exact', 'value' => ''])
    <x-signal.ui.select-field :id="$prefix.'-kind-'.$i" :name="'steps['.$i.'][kind]'" :label="__('Step :number', ['number' => $i + 1])">
        <option value="pageview" @selected($step['kind'] === 'pageview')>{{ __('Page') }}</option>
        <option value="event" @selected($step['kind'] === 'event')>{{ __('Custom event') }}</option>
    </x-signal.ui.select-field>
    <x-signal.ui.select-field :id="$prefix.'-match-'.$i" :name="'steps['.$i.'][match]'" :label="__('Match')">
        <option value="exact" @selected($step['match'] === 'exact')>{{ __('Exactly') }}</option>
        <option value="prefix" @selected($step['match'] === 'prefix')>{{ __('Starts with') }}</option>
    </x-signal.ui.select-field>
    <x-signal.ui.input-field :id="$prefix.'-value-'.$i" :name="'steps['.$i.'][value]'" :label="__('Path or event name')" :value="$step['value']" maxlength="255" :placeholder="$i === 0 ? '/pricing' : ''" :restore="false" />
@endfor
