{{-- The report's period: a preset, or custom dates that override it, and what to compare with. --}}
<x-signal.ui.select-field name="days" :label="__('Period')" :show-errors="false">
    @foreach ([1 => __('Today'), 7 => __('Last 7 days'), 30 => __('Last 30 days'), 90 => __('Last 90 days'), 365 => __('Last 12 months')] as $value => $label)
        <option value="{{ $value }}" @selected(! $period->custom && $period->days === $value)>{{ $label }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field name="from" type="date" :label="__('From')" :value="$period->custom ? $period->start->toDateString() : null" :max="now($site->timezone)->toDateString()" :restore="false" :show-errors="false" />
<x-signal.ui.input-field name="to" type="date" :label="__('To')" :value="$period->custom ? $period->end->toDateString() : null" :max="now($site->timezone)->toDateString()" :restore="false" :show-errors="false" />
<x-signal.ui.select-field name="compare" :label="__('Compare with')" :show-errors="false">
    @foreach (\App\Data\Analytics\ReportPeriod::COMPARISONS as $value => $label)
        <option value="{{ $value }}" @selected($period->compare === $value)>{{ __($label) }}</option>
    @endforeach
</x-signal.ui.select-field>
