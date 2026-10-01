{{-- The event checkboxes for a webhook endpoint form. $prefix keeps IDs unique; $selected is the endpoint's events. --}}
<fieldset class="grid gap-3">
    <legend class="text-sm font-bold text-ink">{{ __('Events') }}</legend>
    <x-signal.ui.checkbox :id="$prefix.'-all'" name="events[]" value="*" :checked="in_array('*', $selected, true)" :restore="false" :error-key="false">{{ __('Everything, including events added later') }}</x-signal.ui.checkbox>
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (\App\Support\Webhooks\WebhookEvents::GROUPS as $group => $events)
            <div class="grid content-start gap-2">
                <p class="text-xs font-bold uppercase tracking-wide text-muted">{{ __($group) }}</p>
                @foreach ($events as $event => $meaning)
                    <x-signal.ui.checkbox :id="$prefix.'-'.str_replace('.', '-', $event)" name="events[]" :value="$event" :checked="in_array($event, $selected, true)" :restore="false" :error-key="false" :description="__($meaning)"><span class="font-mono text-xs">{{ $event }}</span></x-signal.ui.checkbox>
                @endforeach
            </div>
        @endforeach
    </div>
    <x-signal.ui.field-error name="events" />
</fieldset>
