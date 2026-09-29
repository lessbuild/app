@php($project = $overview->project)
@php($observation = $rule->observation ?? [])
@php($routedIds = session()->hasOldInput('opened') ? array_map('intval', (array) old('destinations', [])) : $routes->modelKeys())
@php($pivot = $routes->first()?->getRelation('pivot'))
@php($stepCount = $escalationLimit === null ? 10 : min(10, $escalationLimit))

<x-signal.layouts.project :overview="$overview" :title="$rule->name" :description="__($rule->metric->label()).' '.$rule->comparisonLabel().' '.$rule->thresholdValue().' · '.$rule->environment->name">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3 text-sm text-muted">
            <x-signal.ui.badge :tone="! $rule->enabled ? 'neutral' : match ($rule->evaluation_state) { 'breaching' => 'danger', 'healthy' => 'success', default => 'neutral' }">
                {{ $rule->trashed() ? __('Archived') : ($rule->enabled ? __(ucfirst(str_replace('_', ' ', $rule->evaluation_state))) : __('Paused')) }}
            </x-signal.ui.badge>
            @if (array_key_exists('value', $observation))
                <span>{{ __('Last value: :value from :samples samples', ['value' => is_numeric($observation['value']) ? round((float) $observation['value'], 3) : '—', 'samples' => $observation['samples'] ?? 0]) }}</span>
            @endif
            @if ($rule->checked_at)<span>· {{ __('Checked :time', ['time' => $rule->checked_at->diffForHumans()]) }}</span>@endif
        </div>
        @if ($canManage)
            <div class="flex gap-2">
                <x-signal.ui.button :href="route('monitoring.rules.edit', [$project, $rule->id])" data-modal-trigger="edit-rule" :data-modal-history-url="route('monitoring.rules.show', [$project, $rule->id, 'dialog' => 'edit-rule'])" variant="secondary" size="sm">{{ __('Edit') }}</x-signal.ui.button>
                <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="archive-rule">{{ __('Archive') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="archive-rule" :route="route('monitoring.rules.archive', [$project, $rule->id])" :title="__('Archive :rule?', ['rule' => $rule->name])" :description="__('It stops checking and an open incident closes as “rule archived”.')" :submit-label="__('Archive rule')">
                    <input type="hidden" name="version" value="{{ $rule->state_version }}">
                </x-signal.overlays.delete-confirmation>
            </div>
        @endif
    </div>

    @if ($incidents->isNotEmpty())
        <x-signal.ui.card class="overflow-hidden">
            <h2 class="border-b border-line px-5 py-3 text-sm font-bold text-ink">{{ __('Incidents') }}</h2>
            <ul class="divide-y divide-line">
                @foreach ($incidents as $incident)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <a href="{{ route('monitoring.incidents.show', [$project, $incident->id]) }}" class="font-bold text-ink hover:underline">{{ $incident->title }}</a>
                        <span class="text-xs text-muted">{{ $incident->opened_at->diffForHumans() }} · {{ __($incident->statusLabel()) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Where alerts go')" :description="__('Destinations told when an incident opens or recovers.')">
            <form method="POST" action="{{ route('monitoring.rules.routing', [$project, $rule->id]) }}" class="grid gap-3 p-4 sm:p-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="version" value="{{ $rule->state_version }}">
                @forelse ($destinations as $destination)
                    <x-signal.ui.checkbox :id="'route-'.$destination->id" name="destinations[]" :value="$destination->id" :checked="in_array($destination->id, $routedIds, true)" :restore="false" error-key="destinations">{{ $destination->name }} <span class="font-normal text-muted">· {{ $destination->type->label() }}</span></x-signal.ui.checkbox>
                @empty
                    <p class="text-sm text-muted">{{ __('No alert destinations yet.') }} <a class="font-bold text-primary hover:underline" href="{{ route('monitoring.destinations', $project) }}">{{ __('Add a destination') }}</a></p>
                @endforelse
                <x-signal.ui.checkbox name="opened" value="1" unchecked-value="0" :checked="(bool) ($pivot?->opened ?? true)">{{ __('When an incident opens') }}</x-signal.ui.checkbox>
                <x-signal.ui.checkbox name="recovered" value="1" unchecked-value="0" :checked="(bool) ($pivot?->recovered ?? true)">{{ __('When it recovers') }}</x-signal.ui.checkbox>
                <div><x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save routing') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Escalation')" :description="__('If the incident is still open after each delay, alert one more destination. Delays must increase.')">
            @if ($stepCount > 0)
                <form method="POST" action="{{ route('monitoring.rules.escalations', [$project, $rule->id]) }}" class="grid gap-3 p-4 sm:p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="version" value="{{ $rule->state_version }}">
                    @error('escalations')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
                    @for ($i = 0; $i < $stepCount; $i++)
                        @php($step = $escalations->get($i))
                        <div class="grid gap-3 sm:grid-cols-2">
                            @php($chosen = (int) old('escalations.'.$i.'.destination_id', $step?->alert_destination_id))
                            <x-signal.ui.select-field :name="'escalations['.$i.'][destination_id]'" :id="'escalation-'.$i.'-destination'" :label="__('Step :step', ['step' => $i + 1])" :error-key="'escalations.'.$i.'.destination_id'">
                                <option value="">{{ __('No step') }}</option>
                                @foreach ($destinations as $destination)
                                    <option value="{{ $destination->id }}" @selected($chosen === $destination->id)>{{ $destination->name }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.input-field :name="'escalations['.$i.'][delay_minutes]'" :id="'escalation-'.$i.'-delay'" :label="__('After (minutes)')" :description="__('Minutes after the incident opens.')" type="number" min="1" max="10080" :value="old('escalations.'.$i.'.delay_minutes', $step?->delay_minutes)" :error-key="'escalations.'.$i.'.delay_minutes'" :restore="false" />
                        </div>
                    @endfor
                    <div><x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save escalation') }}</x-signal.ui.button></div>
                </form>
            @else
                <p class="p-4 text-sm text-muted sm:p-6">{{ __('Escalation steps come with Monitoring Pro and above.') }}</p>
            @endif
        </x-signal.ui.settings-section>
    @endif

    @if ($canManage)
        <x-signal.overlays.page-modal id="edit-rule" :title="__('Edit :rule', ['rule' => $rule->name])" :src="route('monitoring.rules.edit', [$project, $rule->id])" size="large" />
    @endif
</x-signal.layouts.project>
