@php($project = $overview->project)
@php($weekdays = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')])

<x-signal.layouts.project :overview="$overview" :title="__('On-call')" :description="__('Rotations that decide who gets paged. Choose a schedule as an email destination’s recipient and alerts go to whoever is on call when they fire.')">
    @include('monitoring._alerts-tabs')

    @if ($canManage)
        <x-slot:actions>
            <x-signal.ui.button :href="route('monitoring.on-call', [$project, 'dialog' => 'new-schedule'])" variant="primary" data-modal-trigger="new-schedule">{{ __('Add a schedule') }}</x-signal.ui.button>
        </x-slot:actions>
        <x-signal.overlays.form-modal id="new-schedule" :title="__('Add an on-call schedule')" :action="route('monitoring.on-call.store', $project)" :submit="__('Add schedule')" form-class="grid items-start gap-5 sm:grid-cols-2">
            @include('monitoring._on-call-fields', ['schedule' => null, 'prefix' => 'new'])
        </x-signal.overlays.form-modal>
    @endif

    @forelse ($schedules as $row)
        @php($schedule = $row['schedule'])
        <x-signal.ui.card as="section" class="grid gap-4 p-5" aria-labelledby="schedule-{{ $schedule->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="schedule-{{ $schedule->id }}" class="text-lg font-extrabold text-ink">{{ $schedule->name }}</h2>
                    <p class="text-sm text-muted">
                        {{ $schedule->rotation === 'weekly' ? __('Weekly, handing over :day at :time', ['day' => $weekdays[$schedule->handoff_day ?? 1], 'time' => $schedule->handoff_time]) : __('Daily, handing over at :time', ['time' => $schedule->handoff_time]) }} ({{ $schedule->timezone }})
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('On call now') }}</p>
                    <p class="text-lg font-extrabold text-ink">{{ $row['now']?->name ?? __('No one') }}</p>
                </div>
            </div>

            <div class="grid gap-2">
                <h3 class="text-sm font-bold text-ink">{{ __('Next turns') }}</h3>
                <ol class="grid gap-1 text-sm">
                    @foreach ($row['upcoming'] as $shift)
                        <li class="flex flex-wrap justify-between gap-2">
                            <span class="font-semibold">{{ $shift['user']?->name ?? __('No one') }}</span>
                            <span class="text-muted">{{ $shift['starts']->setTimezone($schedule->timezone)->isoFormat('ddd D MMM, HH:mm') }} – {{ $shift['ends']->setTimezone($schedule->timezone)->isoFormat('ddd D MMM, HH:mm') }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>

            @if ($schedule->overrides->isNotEmpty())
                <div class="grid gap-2">
                    <h3 class="text-sm font-bold text-ink">{{ __('Cover') }}</h3>
                    @foreach ($schedule->overrides as $override)
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span><span class="font-semibold">{{ $override->user->name }}</span> <span class="text-muted">{{ $override->starts_at->setTimezone($schedule->timezone)->isoFormat('ddd D MMM, HH:mm') }} – {{ $override->ends_at->setTimezone($schedule->timezone)->isoFormat('ddd D MMM, HH:mm') }}</span></span>
                            @if ($canManage)
                                <form method="POST" action="{{ route('monitoring.on-call.overrides.destroy', [$project, $override->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    <x-signal.ui.button :href="route('monitoring.on-call', [$project, 'dialog' => 'cover-'.$schedule->id])" variant="secondary" size="sm" :data-modal-trigger="'cover-'.$schedule->id">{{ __('Add cover') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="route('monitoring.on-call', [$project, 'dialog' => 'edit-schedule-'.$schedule->id])" variant="quiet" size="sm" :data-modal-trigger="'edit-schedule-'.$schedule->id">{{ __('Edit') }}</x-signal.ui.button>
                    <form method="POST" action="{{ route('monitoring.on-call.destroy', [$project, $schedule->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Delete') }}</x-signal.ui.button></form>
                </div>
                <x-signal.overlays.form-modal :id="'cover-'.$schedule->id" :title="__('Add cover')" :description="__('Put someone on call for a while instead, such as while the person whose turn it is is away. Times are in :zone.', ['zone' => $schedule->timezone])" :action="route('monitoring.on-call.overrides.store', [$project, $schedule->id])" :submit="__('Add cover')" form-class="grid items-start gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-signal.ui.select-field :id="'cover-'.$schedule->id.'-user'" name="user_id" :label="__('Who covers')">
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                    </div>
                    <x-signal.ui.input-field :id="'cover-'.$schedule->id.'-starts'" name="starts_at" type="datetime-local" :label="__('From')" required />
                    <x-signal.ui.input-field :id="'cover-'.$schedule->id.'-ends'" name="ends_at" type="datetime-local" :label="__('Until')" required />
                </x-signal.overlays.form-modal>
                <x-signal.overlays.form-modal :id="'edit-schedule-'.$schedule->id" :title="__('Edit schedule')" :action="route('monitoring.on-call.update', [$project, $schedule->id])" method="PUT" :submit="__('Save')" form-class="grid items-start gap-5 sm:grid-cols-2">
                    @include('monitoring._on-call-fields', ['schedule' => $schedule, 'prefix' => 'edit-'.$schedule->id])
                </x-signal.overlays.form-modal>
            @endif
        </x-signal.ui.card>
    @empty
        <x-signal.ui.empty-state icon="users" :title="__('No on-call schedules yet')" :description="__('Add a rotation, then choose it as the recipient of an email destination so alerts reach whoever is on call.')" />
    @endforelse
</x-signal.layouts.project>
