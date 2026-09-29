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

    <x-signal.ui.settings-section id="streams" :title="__('Streams')" :description="__('Send every new entry, as it happens, to a Slack channel, a signed webhook (for a SIEM) or S3-compatible storage for long-term keeping. A stream that fails 20 times in a row is paused.')">
        <div class="grid gap-3 p-4 sm:p-6">
            @if (session('stream_secret'))
                <x-signal.ui.alert tone="info">{{ __('Signing secret (shown once): :secret — requests carry X-BuildPusher-Signature: v1=HMAC-SHA256 of the timestamp, a dot and the body.', ['secret' => session('stream_secret')]) }}</x-signal.ui.alert>
            @endif
            @forelse ($streams as $stream)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <span class="font-bold">{{ $stream->name }}</span>
                        <span class="text-muted">· {{ __(\App\Models\AuditStream::TYPES[$stream->type] ?? $stream->type) }}@if ($stream->destination) ({{ $stream->destination->name }})@endif</span>
                        @if (! $stream->enabled)<x-signal.ui.badge tone="danger">{{ __('Paused') }}</x-signal.ui.badge>@endif
                        @if ($stream->last_error)<span class="block text-xs text-danger">{{ $stream->last_error }}</span>@elseif ($stream->last_delivered_at)<span class="block text-xs text-muted">{{ __('Last sent :time', ['time' => $stream->last_delivered_at->diffForHumans()]) }}</span>@endif
                    </span>
                    @if ($canManageStreams)
                        <form method="POST" action="{{ route('account.audit-log.streams.destroy', $stream->id) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No streams. Entries stay here for :days days.', ['days' => $retentionDays]) }}</p>
            @endforelse
            @if ($canManageStreams)
                <div><x-signal.ui.button :href="route('account.audit-log', ['dialog' => 'add-stream'])" variant="secondary" data-modal-trigger="add-stream">{{ __('Add a stream') }}</x-signal.ui.button></div>
                <x-signal.overlays.form-modal id="add-stream" :title="__('Add an audit stream')" :action="route('account.audit-log.streams.store')" :submit="__('Add stream')" form-class="grid items-start gap-5 sm:grid-cols-2">
                    <x-signal.ui.input-field id="stream-name" name="name" :label="__('Name')" maxlength="120" placeholder="SIEM" required />
                    <x-signal.ui.select-field id="stream-type" name="type" :label="__('Send to')">
                        @foreach (\App\Models\AuditStream::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <div class="sm:col-span-2"><x-signal.ui.input-field id="stream-url" name="endpoint_url" type="url" :label="__('Slack or webhook address')" :description="__('For Slack and webhooks.')" maxlength="2048" /></div>
                    <div class="sm:col-span-2">
                        <x-signal.ui.select-field id="stream-destination" name="backup_destination_id" :label="__('Backup destination')" :description="__('For S3: one JSON file per entry under audit/ in the destination’s bucket.')">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($backupDestinations as $destination)
                                <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                    </div>
                </x-signal.overlays.form-modal>
            @endif
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.account>
