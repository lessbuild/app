@php($project = $overview->project)
@php($selected = collect(old('monitor_ids', session()->hasOldInput() ? [] : ($page?->components->pluck('monitor_id')->all() ?? [])))->filter(fn ($id) => is_scalar($id))->map(fn ($id) => (int) $id)->all())

<x-signal.layouts.project :overview="$overview" :title="$page ? __('Edit :page', ['page' => $page->name]) : __('Add a status page')" :description="__('Only choose monitors you’re happy to show publicly. Addresses, keys and settings never appear on the page.')">
    <form method="POST" action="{{ $page ? route('monitoring.status-pages.update', [$project, $page->id]) : route('monitoring.status-pages.store', $project) }}" class="grid gap-6">
        @csrf
        @if ($page) @method('PUT') @endif
        <x-signal.ui.card>
            <div class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                <x-signal.ui.input-field name="name" :label="__('Name')" :value="$page?->name" maxlength="120" required />
                <x-signal.ui.input-field name="slug" :label="__('Public address')" :value="$page?->slug" maxlength="100" placeholder="acme" :description="__('Shown as :url/status/…. Leave empty to make one from the name.', ['url' => url('/')])" />
                <div class="sm:col-span-2">
                    <x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$page?->description" maxlength="1000" rows="3" :description="__('Optional. Shown under the page title.')" />
                </div>
                <div class="sm:col-span-2">
                    <x-signal.ui.checkbox name="published" value="1" unchecked-value="0" :checked="session()->hasOldInput() ? (bool) old('published') : (bool) ($page?->published ?? false)" :restore="false" :description="__('Anyone with the address sees component names, their health, open incidents and your updates.')">{{ __('Published') }}</x-signal.ui.checkbox>
                </div>
            </div>
        </x-signal.ui.card>

        <x-signal.ui.settings-section :title="__('Components')" :description="__('Monitors shown on the page, in this order. Up to 25. Archived monitors aren’t shown.')">
            <fieldset class="grid gap-3 p-4 sm:p-6" @if ($errors->has('monitor_ids')) aria-describedby="monitor_ids-error" @endif>
                <legend class="sr-only">{{ __('Components') }}</legend>
                @forelse ($monitors as $monitor)
                    <x-signal.ui.choice :id="'component-'.$monitor->id" name="monitor_ids[]" :value="$monitor->id" :checked="in_array($monitor->id, $selected, true)" :restore="false" :error-key="false"
                        :label="$monitor->name" :description="__($monitor->typeLabel()).' · '.$monitor->environment->project->name.' / '.$monitor->environment->name" card />
                @empty
                    <p class="text-sm text-muted">{{ __('Add a monitor first. Pages show monitors, not telemetry.') }}</p>
                @endforelse
                <x-signal.ui.field-error name="monitor_ids" id="monitor_ids-error" />
            </fieldset>
        </x-signal.ui.settings-section>

        <div class="flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ $page ? __('Save status page') : __('Add status page') }}</x-signal.ui.button>
            <x-signal.ui.button :href="$page ? route('monitoring.status-pages.show', [$project, $page->id]) : route('monitoring.status-pages', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
        </div>
    </form>

    @if ($page)
        <x-signal.ui.settings-section :title="__('Delete this page')" :description="__('The public address stops working at once, and its updates and subscribers are deleted. Monitors aren’t affected.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-status-page">{{ __('Delete page') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-status-page" :route="route('monitoring.status-pages.destroy', [$project, $page->id])" :title="__('Delete :page?', ['page' => $page->name])" :description="__('The public address stops working at once.')" :submit-label="__('Delete page')" />
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
