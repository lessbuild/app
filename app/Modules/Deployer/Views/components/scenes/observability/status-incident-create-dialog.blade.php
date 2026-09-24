@props([
    'statusPages',
    'open' => false,
])

<x-dialogs.modal
    id="status-incident-create-dialog"
    :title="__('Publish a status update')"
    :description="__('Publish an incident or maintenance update and notify confirmed subscribers.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.incidents.store') }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        <x-signal.ui.input type="hidden" name="_status_incident_form" value="create" :restore="false" />
        <h3 class="sm:col-span-2 font-bold text-ink">{{ __('Publish a status update') }}</h3>
        <label class="block">
            <span class="ui-label">{{ __('Status page') }}</span>
            <x-signal.ui.select name="status_page_id" class="ui-input" required>
                @foreach ($statusPages as $page)
                    <option value="{{ $page->id }}" @selected((string) old('status_page_id', $statusPages->first()?->id) === (string) $page->id)>{{ $page->name }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="status_page_id" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Kind') }}</span>
            <x-signal.ui.select name="kind" class="ui-input">
                <option value="incident" @selected(old('kind', 'incident') === 'incident')>{{ __('Incident') }}</option>
                <option value="maintenance" @selected(old('kind') === 'maintenance')>{{ __('Planned maintenance') }}</option>
            </x-signal.ui.select>
            <x-forms.errors name="kind" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Status') }}</span>
            <x-signal.ui.select name="status" class="ui-input">
                @foreach (['investigating' => __('Investigating'), 'identified' => __('Identified'), 'monitoring' => __('Monitoring'), 'resolved' => __('Resolved'), 'scheduled' => __('Scheduled'), 'in_progress' => __('In progress'), 'completed' => __('Completed')] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', 'investigating') === $value)>{{ $label }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="status" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Severity') }}</span>
            <x-signal.ui.select name="severity" class="ui-input">
                @foreach (['minor' => __('Minor'), 'major' => __('Major'), 'critical' => __('Critical')] as $value => $label)
                    <option value="{{ $value }}" @selected(old('severity', 'minor') === $value)>{{ $label }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="severity" />
        </label>
        <label class="block sm:col-span-2">
            <span class="ui-label">{{ __('Title') }}</span>
            <x-signal.ui.input name="title" value="{{ old('title') }}" placeholder="{{ __('API latency') }}" required class="ui-input" :restore="false" />
            <x-forms.errors name="title" />
        </label>
        <label class="block sm:col-span-2">
            <span class="ui-label">{{ __('Message') }}</span>
            <x-signal.ui.textarea name="message" placeholder="{{ __('What users should know') }}" required class="ui-input" :restore="false">{{ old('message') }}</x-signal.ui.textarea>
            <x-forms.errors name="message" />
        </label>
        <x-signal.ui.input type="hidden" name="root_cause" value="" :restore="false" />
        <x-signal.ui.input type="hidden" name="remediation" value="" :restore="false" />
        <x-signal.ui.input type="hidden" name="follow_up" value="" :restore="false" />
        <label class="block">
            <span class="ui-label">{{ __('Starts') }}</span>
            <x-signal.ui.input type="datetime-local" name="starts_at" value="{{ old('starts_at', now()->format('Y-m-d\TH:i')) }}" required class="ui-input" :restore="false" />
            <x-forms.errors name="starts_at" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Ends (maintenance)') }}</span>
            <x-signal.ui.input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="ui-input" :restore="false" />
            <x-forms.errors name="ends_at" />
        </label>
        <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Publish status update') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
