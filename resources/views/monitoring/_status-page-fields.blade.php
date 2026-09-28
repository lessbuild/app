@php($selected = collect(old('monitor_ids', session()->hasOldInput() ? [] : ($page?->components->pluck('monitor_id')->all() ?? [])))->filter(fn ($id) => is_scalar($id))->map(fn ($id) => (int) $id)->all())
{{-- A status page's fields: shared by the full page and the Add a status page modal. --}}
<div class="grid items-start gap-5 sm:grid-cols-2">
    <x-signal.ui.input-field name="name" :label="__('Name')" :value="$page?->name" maxlength="120" required />
    <x-signal.ui.input-field name="slug" :label="__('Public address')" :value="$page?->slug" maxlength="100" placeholder="acme" :description="__('Shown as :url/status/…. Leave empty to make one from the name.', ['url' => url('/')])" />
    <div class="sm:col-span-2">
        <x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$page?->description" maxlength="1000" rows="3" :description="__('Optional. Shown under the page title.')" />
    </div>
    <div class="sm:col-span-2">
        <x-signal.ui.checkbox name="published" value="1" unchecked-value="0" :checked="session()->hasOldInput() ? (bool) old('published') : (bool) ($page?->published ?? false)" :restore="false" :description="__('Anyone with the address sees component names, their health, open incidents and your updates.')">{{ __('Published') }}</x-signal.ui.checkbox>
    </div>
</div>
<fieldset class="grid gap-3" @if ($errors->has('monitor_ids')) aria-describedby="monitor_ids-error" @endif>
    <legend class="text-sm font-bold text-ink">{{ __('Components') }}</legend>
    <p class="text-xs text-muted">{{ __('Monitors shown on the page, in this order. Up to 25. Archived monitors aren’t shown.') }}</p>
    @forelse ($monitors as $monitor)
        <x-signal.ui.choice :id="'component-'.$monitor->id" name="monitor_ids[]" :value="$monitor->id" :checked="in_array($monitor->id, $selected, true)" :restore="false" :error-key="false"
            :label="$monitor->name" :description="__($monitor->typeLabel()).' · '.$monitor->environment->project->name.' / '.$monitor->environment->name" card />
    @empty
        <p class="text-sm text-muted">{{ __('Add a monitor first. Pages show monitors, not telemetry.') }}</p>
    @endforelse
    <x-signal.ui.field-error name="monitor_ids" id="monitor_ids-error" />
</fieldset>
