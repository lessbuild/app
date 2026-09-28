<x-signal.layouts.account :account="$account" :title="__('Audit log')" :description="__('Who changed what in this account over the last :days days.', ['days' => $retentionDays])">
    <form method="GET" action="{{ route('account.audit-log') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[repeat(5,minmax(0,1fr))_auto] lg:items-end">
        @if ($projects !== [])
            <x-signal.ui.select-field name="project" :label="__('Project')" :show-errors="false">
                <option value="">{{ __('All of :account', ['account' => $account->name]) }}</option>
                @foreach ($projects as $project)
                    <option value="{{ $project['id'] }}" @selected($filters->projectId === $project['id'])>{{ $project['name'] }}</option>
                @endforeach
            </x-signal.ui.select-field>
        @endif
        <x-signal.ui.select-field name="person" :label="__('Person')" :show-errors="false">
            <option value="">{{ __('Anyone') }}</option>
            @foreach ($members as $member)
                <option value="{{ $member->id }}" @selected($filters->actorId === $member->id)>{{ $member->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="category" :label="__('Kind of change')" :show-errors="false">
            <option value="">{{ __('Everything') }}</option>
            @foreach ($categories as $key => $label)
                <option value="{{ $key }}" @selected($filters->category === $key)>{{ __($label) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.input-field name="from" type="date" :label="__('From')" :value="$filters->from?->toDateString()" :restore="false" :show-errors="false" />
        <x-signal.ui.input-field name="to" type="date" :label="__('To')" :value="$filters->to?->toDateString()" :restore="false" :show-errors="false" />
        <div class="flex gap-2">
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Show') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('account.audit-log.export', request()->query())" variant="quiet">{{ __('Export CSV') }}</x-signal.ui.button>
        </div>
    </form>
    @if (session('status'))<x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>@endif
    @error('saved_view_name')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    <x-signal.ui.saved-views page="audit-log" />

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
