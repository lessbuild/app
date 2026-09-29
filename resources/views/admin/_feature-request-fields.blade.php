{{-- Title, description and status of a roadmap request; $item is null for a new one. The title is optional in the browser when an existing request can be picked instead. --}}
@php($prefix = $prefix ?? 'feature')
@php($titleRequired = $titleRequired ?? true)
<div class="sm:col-span-2"><x-signal.ui.input-field :id="$prefix.'-title'" name="title" :label="__('Public title')" :value="$item?->title" maxlength="120" :required="$titleRequired" /></div>
<div class="sm:col-span-2"><x-signal.ui.textarea-field :id="$prefix.'-description'" name="description" :label="__('Public description')" :description="__('Optional. Shown on the roadmap; don’t quote private feedback.')" :value="$item?->description" rows="3" maxlength="2000" /></div>
<x-signal.ui.select-field :id="$prefix.'-status'" name="status" :label="__('Status')">
    @foreach (\App\Models\FeatureRequest::STATUSES as $value => $label)
        <option value="{{ $value }}" @selected(($item?->status ?? 'under_review') === $value)>{{ __($label) }}</option>
    @endforeach
</x-signal.ui.select-field>
