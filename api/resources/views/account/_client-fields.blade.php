{{-- A client's fields. $prefix keeps IDs unique; $client is null for a new one. --}}
<x-signal.ui.input-field :id="$prefix.'-name'" name="name" :label="__('Client name')" :value="$client?->name" maxlength="120" :restore="$client === null" required />
<x-signal.ui.input-field :id="$prefix.'-emails'" name="emails" :label="__('Report recipients')" :value="$client ? implode(', ', $client->emails) : null" :description="__('Up to five email addresses, separated by commas.')" maxlength="1000" :restore="$client === null" />
<fieldset class="grid gap-2">
    <legend class="text-sm font-bold text-ink">{{ __('Their projects') }}</legend>
    @forelse ($projects as $project)
        <x-signal.ui.checkbox :id="$prefix.'-project-'.$project->id" name="project_ids[]" :value="$project->id" :checked="in_array($project->id, $client->project_ids ?? [], true)" :restore="false" :error-key="false">{{ $project->name }}</x-signal.ui.checkbox>
    @empty
        <p class="text-sm text-muted">{{ __('No projects yet.') }}</p>
    @endforelse
</fieldset>
<x-signal.ui.input-field :id="$prefix.'-markup'" name="markup_percent" type="number" min="0" max="500" :label="__('Markup on costs (%)')" :value="$client->markup_percent ?? 0" :restore="$client === null" />
<x-signal.ui.checkbox :id="$prefix.'-monthly'" name="monthly_report" :checked="$client->monthly_report ?? true" :restore="false">{{ __('Email them a report on the 1st of each month') }}</x-signal.ui.checkbox>
