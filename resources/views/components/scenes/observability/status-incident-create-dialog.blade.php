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
        <input type="hidden" name="_status_incident_form" value="create">
        <h3 class="sm:col-span-2 font-bold text-primary">{{ __('Publish a status update') }}</h3>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Status page') }}</span>
            <select name="status_page_id" class="input secondary w-full rounded-md" required>
                @foreach ($statusPages as $page)
                    <option value="{{ $page->id }}" @selected((string) old('status_page_id', $statusPages->first()?->id) === (string) $page->id)>{{ $page->name }}</option>
                @endforeach
            </select>
            <x-forms.errors name="status_page_id" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Kind') }}</span>
            <select name="kind" class="input secondary w-full rounded-md">
                <option value="incident" @selected(old('kind', 'incident') === 'incident')>{{ __('Incident') }}</option>
                <option value="maintenance" @selected(old('kind') === 'maintenance')>{{ __('Planned maintenance') }}</option>
            </select>
            <x-forms.errors name="kind" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Status') }}</span>
            <select name="status" class="input secondary w-full rounded-md">
                @foreach (['investigating' => __('Investigating'), 'identified' => __('Identified'), 'monitoring' => __('Monitoring'), 'resolved' => __('Resolved'), 'scheduled' => __('Scheduled'), 'in_progress' => __('In progress'), 'completed' => __('Completed')] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', 'investigating') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-forms.errors name="status" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Severity') }}</span>
            <select name="severity" class="input secondary w-full rounded-md">
                @foreach (['minor' => __('Minor'), 'major' => __('Major'), 'critical' => __('Critical')] as $value => $label)
                    <option value="{{ $value }}" @selected(old('severity', 'minor') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-forms.errors name="severity" />
        </label>
        <label class="block sm:col-span-2">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Title') }}</span>
            <input name="title" value="{{ old('title') }}" placeholder="{{ __('API latency') }}" required class="input secondary w-full rounded-md">
            <x-forms.errors name="title" />
        </label>
        <label class="block sm:col-span-2">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Message') }}</span>
            <textarea name="message" placeholder="{{ __('What users should know') }}" required class="input secondary w-full rounded-md">{{ old('message') }}</textarea>
            <x-forms.errors name="message" />
        </label>
        <input type="hidden" name="root_cause" value="">
        <input type="hidden" name="remediation" value="">
        <input type="hidden" name="follow_up" value="">
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Starts') }}</span>
            <input type="datetime-local" name="starts_at" value="{{ old('starts_at', now()->format('Y-m-d\TH:i')) }}" required class="input secondary w-full rounded-md">
            <x-forms.errors name="starts_at" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Ends (maintenance)') }}</span>
            <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="input secondary w-full rounded-md">
            <x-forms.errors name="ends_at" />
        </label>
        <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Publish status update') }}</x-ui.button>
    </form>
</x-dialogs.modal>
