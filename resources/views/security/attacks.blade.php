@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Attacks')" :description="__('Addresses blocked for attacking this project’s servers: repeated failed sign-ins, probing for vulnerable paths, or flooding a site.')">
    @unless ($included)
        <x-signal.ui.alert tone="info">{{ __('Automatic blocking comes with the Pro Security plan and above.') }} <a class="ui-link" href="{{ route('account.billing', ['tab' => 'security']) }}">{{ __('See plans') }}</a></x-signal.ui.alert>
    @endunless

    <x-signal.ui.settings-section :title="__('Blocking')" :description="__('Every five minutes, the last ten minutes of each website’s access log are checked. Blocks go into the server’s firewall and lift on their own. Behind Cloudflare’s proxy, block at Cloudflare instead (Firewall).')">
        <form method="POST" action="{{ route('security.attacks.settings', $project) }}" class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6">
            @csrf @method('PUT')
            <fieldset class="contents" @disabled(! $canManage)>
                <div class="sm:col-span-2"><x-signal.ui.checkbox name="autoblock" :checked="$settings->autoblock">{{ __('Block attackers automatically') }}</x-signal.ui.checkbox></div>
                <x-signal.ui.input-field name="block_hours" type="number" min="1" max="720" :label="__('Block for (hours)')" :value="$settings->block_hours" />
                <x-signal.ui.textarea-field name="allowlist" :label="__('Never block')" :value="implode(PHP_EOL, $settings->allowlist ?? [])" rows="3" :description="__('Addresses or networks, one per line, such as your office or a monitoring service.')" />
                @if ($canManage)<div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save') }}</x-signal.ui.button></div>@endif
            </fieldset>
        </form>
    </x-signal.ui.settings-section>

    <x-signal.ui.card as="section" class="p-5 sm:p-6" aria-labelledby="active-blocks">
        <h2 id="active-blocks" class="text-lg font-extrabold text-ink">{{ __('Blocked now') }}</h2>
        @if ($active->isEmpty())
            <p class="mt-3 text-sm text-muted">{{ __('No one is blocked right now.') }}</p>
        @else
            <ul class="mt-2 divide-y divide-line">
                @foreach ($active as $block)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <span>
                            <span class="font-mono font-bold text-ink">{{ $block->ip }}</span>
                            <span class="text-muted">· {{ __(\App\Models\SecurityBlock::REASONS[$block->reason] ?? $block->reason) }} · {{ $block->server->display_name ?: $block->server->name }} · {{ $block->detail }}</span>
                            <span class="block text-xs text-muted">{{ __('Until :time', ['time' => $block->expires_at->diffForHumans()]) }}</span>
                        </span>
                        @if ($canManage)
                            <form method="POST" action="{{ route('security.attacks.destroy', [$project, $block->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Unblock') }}</x-signal.ui.button></form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-signal.ui.card>

    @if ($history->isNotEmpty())
        <x-signal.ui.card as="section" class="p-5 sm:p-6" aria-labelledby="past-blocks">
            <h2 id="past-blocks" class="text-lg font-extrabold text-ink">{{ __('Recently lifted') }}</h2>
            <ul class="mt-2 grid gap-1 text-sm text-muted">
                @foreach ($history as $block)
                    <li><span class="font-mono text-ink">{{ $block->ip }}</span> · {{ __(\App\Models\SecurityBlock::REASONS[$block->reason] ?? $block->reason) }} · {{ $block->created_at?->isoFormat('D MMM, HH:mm') }}</li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.project>
