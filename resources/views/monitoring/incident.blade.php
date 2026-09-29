@php($project = $overview->project)
@php($observation = \App\Data\Monitoring\MonitorObservation::class)
@php($latestReason = is_string($incident->latest_observation['reason'] ?? null) ? $incident->latest_observation['reason'] : null)

<x-signal.layouts.project :overview="$overview" :title="$incident->title" :description="'#'.$incident->id.' · '.__($incident->statusLabel())">
    <div class="grid items-start gap-6 xl:grid-cols-3">
        <div class="grid gap-6 xl:col-span-2" id="incident-live" data-live-region data-live-interval="5000">
            <x-signal.ui.card class="grid gap-3 p-5 text-sm">
                <p class="flex flex-wrap items-center gap-2">
                    <x-signal.ui.badge :tone="match ($incident->status) { 'open' => 'danger', 'acknowledged' => 'warning', default => 'neutral' }">{{ __($incident->statusLabel()) }}</x-signal.ui.badge>
                    <span class="text-muted">{{ $observation::label($latestReason) }}</span>
                </p>
                @if (is_array($incident->latest_observation['details'] ?? null))
                    @foreach (\App\Support\Monitoring\ObservationText::details($incident->latest_observation['details']) as $line)
                        <p class="text-xs text-muted">{{ $line }}</p>
                    @endforeach
                @endif
                <p class="text-muted">{{ \App\Support\Monitoring\ObservationText::configuration($incident->rule_snapshot) }}</p>
                <p class="text-muted">{{ __('Opened :opened UTC · last failure :breached UTC', ['opened' => $incident->opened_at->format('Y-m-d H:i:s'), 'breached' => $incident->last_breached_at->format('Y-m-d H:i:s')]) }}</p>
                @if ($incident->resolved_at)
                    <p>{{ __(':status at :time UTC.', ['status' => __($incident->statusLabel()), 'time' => $incident->resolved_at->format('Y-m-d H:i:s')]) }}</p>
                @endif
                @if ($incident->acknowledged_at)
                    <p>{{ __('Acknowledged by :name at :time UTC.', ['name' => $incident->acknowledgedBy->name ?? __('a former member'), 'time' => $incident->acknowledged_at->format('Y-m-d H:i:s')]) }}</p>
                @endif
                <p>{{ __('Assigned to: :name', ['name' => $incident->assignee->name ?? __('no one')]) }}</p>
                @if ($incident->monitor_id)
                    <p><a class="font-bold text-primary hover:underline" href="{{ route('monitoring.monitors.show', [$project, $incident->monitor_id]) }}">{{ __('View the monitor and its checks') }}</a></p>
                @endif
            </x-signal.ui.card>

            {{-- The post-mortem: written from a template once the dust settles, and publishable as a status page report. --}}
            @php($sections = \App\Actions\Monitoring\SaveIncidentPostmortem::SECTIONS)
            <x-signal.ui.card as="section" class="grid gap-4 p-5" aria-labelledby="postmortem-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="postmortem-heading" class="text-sm font-bold text-ink">{{ __('Post-mortem') }}</h2>
                    @if ($canRespond)
                        <div class="flex flex-wrap gap-2">
                            <x-signal.ui.button :href="route('monitoring.incidents.show', [$project, $incident->id, 'dialog' => 'postmortem'])" variant="secondary" size="sm" data-modal-trigger="postmortem">{{ $incident->postmortem ? __('Edit post-mortem') : __('Write a post-mortem') }}</x-signal.ui.button>
                            @if ($incident->postmortem && $incident->resolved_at && $statusPages->isNotEmpty())
                                <x-signal.ui.button :href="route('monitoring.incidents.show', [$project, $incident->id, 'dialog' => 'publish-postmortem'])" variant="quiet" size="sm" data-modal-trigger="publish-postmortem">{{ $publishedReport ? __('Update status page report') : __('Publish to status page') }}</x-signal.ui.button>
                            @endif
                        </div>
                    @endif
                </div>
                @if ($incident->postmortem)
                    <dl class="grid gap-3 text-sm">
                        @foreach ($sections as $key => $heading)
                            @isset($incident->postmortem[$key])
                                <div><dt class="font-bold text-ink">{{ __($heading) }}</dt><dd class="mt-1 whitespace-pre-wrap break-words text-muted">{{ $incident->postmortem[$key] }}</dd></div>
                            @endisset
                        @endforeach
                    </dl>
                    @if ($publishedReport)
                        <p class="text-xs text-muted">{{ __('Published to :page.', ['page' => $publishedReport->statusPage->name]) }} @if ($publishedReport->statusPage->published)<a href="{{ $publishedReport->statusPage->publicUrl() }}" class="font-bold text-primary underline" target="_blank" rel="noopener">{{ __('View') }}</a>@endif</p>
                    @endif
                @else
                    <p class="text-sm text-muted">{{ __('Once it’s resolved, write up what happened, the impact, the root cause, how it was fixed and what happens next.') }}</p>
                @endif
            </x-signal.ui.card>

            <section class="grid gap-3" aria-labelledby="timeline-heading">
                <h2 id="timeline-heading" class="text-sm font-bold text-ink">{{ __('Timeline') }}</h2>
                <ol class="grid gap-3">
                    @foreach ($activities as $activity)
                        <li class="rounded-panel border border-line bg-surface p-4">
                            <div class="flex flex-wrap justify-between gap-2">
                                <p class="text-sm font-semibold text-ink">{{ __($activity->label()) }}</p>
                                <time class="text-xs text-muted" datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->format('Y-m-d H:i:s') }} UTC</time>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $activity->actor->name ?? __('Automatic') }}</p>
                            @if ($activity->note !== null)
                                <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $activity->note }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        {{-- Outside the live region, so a refresh never clears what's being typed. --}}
            @if ($canRespond)
                <x-signal.overlays.form-modal id="postmortem" :title="__('Post-mortem')" :description="__('Blameless and specific: what happened and what changes because of it.')" :action="route('monitoring.incidents.postmortem', [$project, $incident->id])" method="PUT" :submit="__('Save post-mortem')">
                    @foreach ($sections as $key => $heading)
                        <x-signal.ui.textarea-field :id="'postmortem-'.$key" :name="$key" :label="__($heading)" :value="old($key, $incident->postmortem[$key] ?? null)" rows="3" maxlength="5000"
                            :description="match ($key) { 'summary' => __('A few sentences anyone can follow.'), 'impact' => __('Who was affected, how, and for how long.'), 'root_cause' => __('Why it happened, not who.'), 'resolution' => __('What was done to fix it.'), default => __('The changes that stop it happening again, with owners.') }" />
                    @endforeach
                </x-signal.overlays.form-modal>
                @if ($incident->postmortem && $incident->resolved_at && $statusPages->isNotEmpty())
                    <x-signal.overlays.form-modal id="publish-postmortem" :title="__('Publish to a status page')" :description="__('Posts a resolved incident report with the summary, impact, root cause, resolution and follow-ups. Subscribers aren’t emailed.')" :action="route('monitoring.incidents.postmortem.publish', [$project, $incident->id])" :submit="__('Publish')" form-class="grid items-start gap-5 sm:grid-cols-2">
                        <x-signal.ui.select-field id="publish-page" name="status_page_id" :label="__('Status page')">
                            @foreach ($statusPages as $statusPage)
                                <option value="{{ $statusPage->id }}" @selected($publishedReport?->status_page_id === $statusPage->id)>{{ $statusPage->name }}@unless ($statusPage->published) ({{ __('draft') }})@endunless</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.select-field id="publish-severity" name="severity" :label="__('Severity')">
                            @foreach (\App\Models\StatusUpdate::SEVERITIES as $severity)
                                <option value="{{ $severity }}" @selected(($publishedReport?->severity ?? 'minor') === $severity)>{{ __(ucfirst($severity)) }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <div class="sm:col-span-2"><x-signal.ui.input-field id="publish-title" name="title" :label="__('Public title')" :value="$publishedReport?->title ?? $incident->title" maxlength="200" required /></div>
                    </x-signal.overlays.form-modal>
                @endif
            @endif

        @if ($canRespond)
            <aside class="order-first grid gap-5 xl:order-none">
                @if ($incident->status === 'open')
                    <form method="POST" action="{{ route('monitoring.incidents.update', [$project, $incident->id]) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="acknowledge">
                        <input type="hidden" name="version" value="{{ $incident->state_version }}">
                        <x-signal.ui.button type="submit" variant="primary" class="w-full">{{ __('Acknowledge') }}</x-signal.ui.button>
                    </form>
                @endif
                <x-signal.ui.card>
                    <form method="POST" action="{{ route('monitoring.incidents.update', [$project, $incident->id]) }}" class="grid gap-3 p-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="version" value="{{ $incident->state_version }}">
                        <x-signal.ui.select-field name="assignee_id" :label="__('Assigned to')">
                            <option value="">{{ __('No one') }}</option>
                            @foreach ($assignees as $member)
                                <option value="{{ $member->id }}" @selected($incident->assignee_id === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                    </form>
                </x-signal.ui.card>
                <x-signal.ui.card>
                    <form method="POST" action="{{ route('monitoring.incidents.update', [$project, $incident->id]) }}" class="grid gap-3 p-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="note">
                        <input type="hidden" name="version" value="{{ $incident->state_version }}">
                        <x-signal.ui.textarea-field name="note" :label="__('Add a note')" rows="3" maxlength="1000" />
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Add note') }}</x-signal.ui.button>
                    </form>
                </x-signal.ui.card>
            </aside>
        @endif
    </div>
</x-signal.layouts.project>
