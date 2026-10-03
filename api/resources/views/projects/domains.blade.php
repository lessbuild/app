@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Domains')" :description="__('Hostnames this project serves. Verify each one so no other account can claim it.')">
    @foreach (['domain', 'hostname', 'environment_id'] as $field)
        @error($field)
            <x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>
        @enderror
    @endforeach

    @if ($overview->canManage)
        <x-signal.ui.card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('projects.domains.store', $project) }}" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end">
                @csrf
                <x-signal.ui.input-field name="hostname" :label="__('Add a domain')" :placeholder="__('shop.example.com')" autocomplete="off" spellcheck="false" required :show-errors="false" />
                <x-signal.ui.select-field name="environment_id" :label="__('Environment')" :show-errors="false">
                    <option value="">{{ __('Any environment') }}</option>
                    @foreach ($overview->environments as $environment)
                        <option value="{{ $environment->id }}" @selected(old('environment_id') === $environment->id)>{{ $environment->name }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.button type="submit" variant="primary">{{ __('Add domain') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    @endif

    @if ($domains === [])
        <x-signal.ui.empty-state icon="globe-alt" :title="__('No domains yet')" :description="__('Add the hostnames this project serves, such as example.com and www.example.com.')" />
    @else
        <ul class="grid gap-4" aria-label="{{ __('Domains') }}">
            @foreach ($domains as $domain)
                <li>
                    <x-signal.ui.card class="grid gap-4 p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 break-all font-extrabold text-ink">
                                    {{ $domain->name }}
                                    <x-signal.ui.badge :tone="$domain->verifiedAt ? 'success' : 'warning'">{{ $domain->verifiedAt ? __('Verified') : __('Not verified') }}</x-signal.ui.badge>
                                </p>
                                <p class="mt-1 text-xs text-muted">
                                    {{ $domain->environment ?? __('Any environment') }}
                                    @if ($domain->verifiedAt)
                                        · {{ __('Verified :time', ['time' => $domain->verifiedAt->diffForHumans()]) }}
                                    @elseif ($domain->lastCheckedAt)
                                        · {{ __('Last checked :time', ['time' => $domain->lastCheckedAt->diffForHumans()]) }}
                                    @endif
                                </p>
                            </div>
                            @if ($overview->canManage)
                                <div class="flex flex-wrap gap-2">
                                    @unless ($domain->verifiedAt)
                                        <form method="POST" action="{{ route('projects.domains.verify', [$project, $domain->id]) }}">
                                            @csrf
                                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Check DNS') }}</x-signal.ui.button>
                                        </form>
                                    @endunless
                                    <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="remove-domain-{{ $domain->id }}">{{ __('Remove') }}</x-signal.ui.button>
                                    <x-signal.overlays.delete-confirmation
                                        :id="'remove-domain-'.$domain->id"
                                        :route="route('projects.domains.destroy', [$project, $domain->id])"
                                        :title="__('Remove :domain?', ['domain' => $domain->name])"
                                        :description="__('The project stops claiming this hostname. Services stop serving it.')"
                                        :submit-label="__('Remove')"
                                    />
                                </div>
                            @endif
                        </div>
                        @unless ($domain->verifiedAt)
                            <div class="grid gap-2 rounded-panel border border-line bg-surface-muted p-4 text-sm">
                                <p class="font-bold text-ink">{{ __('Add this TXT record at your DNS provider') }}</p>
                                <dl class="grid gap-2 sm:grid-cols-[6rem_minmax(0,1fr)]">
                                    <dt class="text-muted">{{ __('Name') }}</dt>
                                    <dd class="break-all font-mono text-ink">{{ $domain->recordName }}</dd>
                                    <dt class="text-muted">{{ __('Value') }}</dt>
                                    <dd class="break-all font-mono text-ink">{{ $domain->recordValue }}</dd>
                                </dl>
                            </div>
                        @endunless
                    </x-signal.ui.card>
                </li>
            @endforeach
        </ul>
    @endif
</x-signal.layouts.project>
