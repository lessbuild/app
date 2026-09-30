@php($project = $overview->project)
@php($days = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 0 => __('Sunday')])

<x-signal.layouts.project :overview="$overview" :title="__('Servers')" :description="__('The servers this project runs on: how well they’re hardened, and when they install security updates.')">
    @unless ($included)
        <x-signal.ui.alert tone="info">{{ __('Server hardening and update windows come with the Pro Security plan and above.') }} <a class="ui-link" href="{{ route('account.billing', ['tab' => 'security']) }}">{{ __('See plans') }}</a></x-signal.ui.alert>
    @endunless

    @forelse ($servers as $server)
        @php($open = $findings->get('server:'.$server->id, collect()))
        <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="server-{{ $server->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="server-{{ $server->id }}" class="text-lg font-extrabold text-ink">{{ $server->display_name ?: $server->name }}</h2>
                    <p class="text-sm text-muted">{{ $server->public_ip }} · {{ $server->region }}</p>
                </div>
                @if ($open->isEmpty())
                    <x-signal.ui.badge tone="success">{{ __('No open findings') }}</x-signal.ui.badge>
                @else
                    <a href="{{ route('security.findings', [$project, 'source' => 'servers']) }}"><x-signal.ui.badge :tone="$open->contains(fn ($f) => in_array($f->severity, ['critical', 'high'], true)) ? 'danger' : 'warning'">{{ trans_choice(':count open finding|:count open findings', $open->count(), ['count' => $open->count()]) }}</x-signal.ui.badge></a>
                @endif
            </div>
            <p class="text-sm text-muted">
                {{ $server->patch_day === null ? __('No update window.') : __('Updates install every :day at :hour:00 UTC:reboot.', ['day' => $days[$server->patch_day], 'hour' => str_pad((string) $server->patch_hour, 2, '0', STR_PAD_LEFT), 'reboot' => $server->patch_reboot ? __(', rebooting when needed') : '']) }}
                @if ($server->last_patched_at) {{ __('Last run :time.', ['time' => $server->last_patched_at->diffForHumans()]) }}@endif
            </p>
            @if ($server->last_patch_error)<x-signal.ui.alert tone="danger">{{ __('The last update failed: :error', ['error' => $server->last_patch_error]) }}</x-signal.ui.alert>@endif
            @if ($canManage && $included)
                <div class="flex flex-wrap items-end gap-3">
                    <form method="POST" action="{{ route('security.servers.patch', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3">
                        @csrf @method('PUT')
                        <x-signal.ui.select-field :id="'patch-day-'.$server->id" name="patch_day" :label="__('Update window')" :show-errors="false">
                            <option value="" @selected($server->patch_day === null)>{{ __('Off') }}</option>
                            @foreach ($days as $value => $label)
                                <option value="{{ $value }}" @selected($server->patch_day === $value)>{{ __('Every :day', ['day' => $label]) }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.select-field :id="'patch-hour-'.$server->id" name="patch_hour" :label="__('At (UTC)')" :show-errors="false">
                            @for ($hour = 0; $hour < 24; $hour++)
                                <option value="{{ $hour }}" @selected($server->patch_hour === $hour)>{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}:00</option>
                            @endfor
                        </x-signal.ui.select-field>
                        <x-signal.ui.checkbox :id="'patch-reboot-'.$server->id" name="patch_reboot" :checked="$server->patch_reboot" :show-errors="false">{{ __('Reboot if needed') }}</x-signal.ui.checkbox>
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                    </form>
                    <form method="POST" action="{{ route('security.servers.patch', [$project, $server->id]) }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="now" value="1">
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Install updates now') }}</x-signal.ui.button>
                    </form>
                </div>
            @endif
        </x-signal.ui.card>
    @empty
        <x-signal.ui.empty-state icon="server" :title="__('No servers yet')" :description="__('Servers show up here once websites in this project run on them.')" />
    @endforelse
</x-signal.layouts.project>
