@php($prefix = $goal ? 'goal-'.$goal->id.'-' : 'new-goal-')
<x-signal.ui.input-field :id="$prefix.'name'" name="name" :label="__('Goal name')" :value="$goal?->name" :placeholder="__('Demo requested')" maxlength="120" required :restore="$goal === null" />
<x-signal.ui.select-field :id="$prefix.'kind'" name="kind" :label="__('What counts')" required>
    <option value="path" @selected(($goal?->kind ?? 'path') === 'path')>{{ __('Visiting a page') }}</option>
    <option value="event" @selected($goal?->kind === 'event')>{{ __('A custom event') }}</option>
</x-signal.ui.select-field>
<x-signal.ui.select-field :id="$prefix.'match_type'" name="match_type" :label="__('Match')" required>
    <option value="exact" @selected(($goal?->match_type ?? 'exact') === 'exact')>{{ __('Exactly') }}</option>
    <option value="prefix" @selected($goal?->match_type === 'prefix')>{{ __('Starts with') }}</option>
</x-signal.ui.select-field>
<x-signal.ui.input-field :id="$prefix.'match_value'" name="match_value" :label="__('Page or event name')" :value="$goal?->match_value" placeholder="/thank-you" maxlength="255" required :restore="$goal === null" />
<input type="hidden" name="active" value="0">
<x-signal.ui.checkbox :id="$prefix.'active'" name="active" value="1" :checked="$goal?->active ?? true" :restore="false">{{ __('Count this goal in reports') }}</x-signal.ui.checkbox>
