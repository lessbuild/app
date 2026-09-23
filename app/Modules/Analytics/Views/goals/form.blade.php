<x-signal.ui.field label="Goal name" name="name" required>
    <x-signal.ui.input name="name" :value="$goal->name ?? ''" placeholder="Demo requested" maxlength="120" required />
</x-signal.ui.field>

<x-signal.ui.field label="Goal type" name="kind" required>
    <x-signal.ui.select name="kind" required>
        <option value="path" @selected(old('kind', $goal->kind ?? 'path') === 'path')>Path</option>
        <option value="event" @selected(old('kind', $goal->kind ?? '') === 'event')>Named event</option>
    </x-signal.ui.select>
</x-signal.ui.field>

<x-signal.ui.field label="Match rule" name="match_type" required>
    <x-signal.ui.select name="match_type" required>
        <option value="exact" @selected(old('match_type', $goal->match_type ?? 'exact') === 'exact')>Exact match</option>
        <option value="prefix" @selected(old('match_type', $goal->match_type ?? '') === 'prefix')>Prefix match</option>
    </x-signal.ui.select>
</x-signal.ui.field>

<x-signal.ui.field label="Match value" name="match_value" required description="Path goals start with `/`. Event goals use the name passed to the tracker.">
    <x-signal.ui.input name="match_value" :value="$goal->match_value ?? ''" placeholder="/thank-you or demo_requested" maxlength="255" required />
</x-signal.ui.field>

<x-signal.ui.checkbox name="active" :checked="$goal->active ?? true" :unchecked-value="0">
    Count this goal in reports
</x-signal.ui.checkbox>
