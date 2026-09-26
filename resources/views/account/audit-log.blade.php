<x-signal.layouts.account :account="$account" :title="__('Audit log')" :description="__('Who changed what in this account over the last :days days.', ['days' => $retentionDays])">
    @if ($entries->isEmpty())
        <x-signal.ui.empty-state :title="__('Nothing recorded yet')" :description="__('Invitations, role changes and other account changes will appear here.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <x-signal.ui.table :caption="__('Audit log')" :framed="false">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('When') }}</th>
                        <th scope="col">{{ __('Who') }}</th>
                        <th scope="col">{{ __('What') }}</th>
                        <th scope="col">{{ __('From') }}</th>
                    </tr>
                </x-slot:head>
                @foreach ($entries as $entry)
                    <tr>
                        <td class="whitespace-nowrap"><time datetime="{{ $entry->at->toIso8601String() }}" title="{{ $entry->at->toDayDateTimeString() }}">{{ $entry->at->diffForHumans() }}</time></td>
                        <td>
                            <span class="block font-bold text-ink">{{ $entry->actor }}</span>
                            @if ($entry->actorEmail)
                                <span class="block text-xs text-muted">{{ $entry->actorEmail }}</span>
                            @endif
                        </td>
                        <td>{{ $entry->description }}</td>
                        <td>
                            <span class="block">{{ $entry->ipAddress ?? '—' }}</span>
                            @if ($entry->device)
                                <span class="block text-xs text-muted">{{ $entry->device }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        </x-signal.ui.card>
        @if ($entries->hasMorePages() || ! $entries->onFirstPage())
            <nav class="flex justify-between gap-3" aria-label="{{ __('Audit log pages') }}">
                <x-signal.ui.button :href="$entries->previousPageUrl()" :disabled="$entries->onFirstPage()" variant="secondary">{{ __('Newer') }}</x-signal.ui.button>
                <x-signal.ui.button :href="$entries->nextPageUrl()" :disabled="! $entries->hasMorePages()" variant="secondary">{{ __('Older') }}</x-signal.ui.button>
            </nav>
        @endif
    @endif
</x-signal.layouts.account>
