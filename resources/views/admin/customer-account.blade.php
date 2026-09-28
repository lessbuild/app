<x-signal.layouts.admin :title="$account->name" :description="__('Account :id · created :date', ['id' => $account->id, 'date' => $account->created_at?->toFormattedDateString()])">
    <div class="grid gap-4 lg:grid-cols-2">
        <x-signal.ui.table :caption="__('Members')">
            <x-slot:head><tr><th scope="col">{{ __('Person') }}</th><th scope="col">{{ __('Role') }}</th></tr></x-slot:head>
            @foreach ($account->memberships as $membership)
                <tr><td><a href="{{ route('admin.customers.users', $membership->user_id) }}" class="text-primary hover:underline">{{ $membership->user->email }}</a></td><td>{{ $membership->role->label() }}@if ($membership->service_access !== null) <span class="text-xs text-muted">· {{ implode(', ', $membership->service_access) }}</span>@endif</td></tr>
            @endforeach
        </x-signal.ui.table>
        <x-signal.ui.table :caption="__('Projects')">
            <x-slot:head><tr><th scope="col">{{ __('Project') }}</th><th scope="col">{{ __('Services') }}</th></tr></x-slot:head>
            @forelse ($account->projects as $project)
                <tr><td>{{ $project->name }}</td><td>{{ $project->enabledServices->pluck('service')->implode(', ') ?: '—' }}</td></tr>
            @empty
                <tr><td colspan="2" class="text-muted">{{ __('No projects.') }}</td></tr>
            @endforelse
        </x-signal.ui.table>
    </div>

    <x-signal.ui.settings-section :title="__('Billing')" :description="$billing ? __('Stripe customer :customer · subscription :status', ['customer' => $billing->stripe_customer_id ?? '—', 'status' => $billing->status]) : __('Never subscribed.')">
        <ul class="divide-y divide-line text-sm">
            @forelse ($selections as $selection)
                <li class="px-4 py-2 sm:px-6">{{ $selection->service }} · {{ $selection->kind->value }} {{ $selection->item_key }}@if ($selection->quantity > 1) ×{{ $selection->quantity }}@endif @if ($selection->ends_at)<span class="text-muted">· {{ __('ends :date', ['date' => $selection->ends_at->toFormattedDateString()]) }}</span>@endif</li>
            @empty
                <li class="px-4 py-2 text-muted sm:px-6">{{ __('Free tiers everywhere.') }}</li>
            @endforelse
            @foreach ($usage as $record)
                <li class="px-4 py-2 text-muted sm:px-6">{{ __('Usage this month: :meter :quantity (:reported reported to Stripe)', ['meter' => $record->meter, 'quantity' => number_format($record->quantity), 'reported' => number_format($record->reported_quantity)]) }}</li>
            @endforeach
        </ul>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Recent activity')" :description="__('The account’s latest 25 audit entries.')">
        <ul class="divide-y divide-line text-sm">
            @forelse ($audit as $entry)
                <li class="px-4 py-2 sm:px-6">{{ $entry->action->describe($entry->context ?? []) }} <span class="text-xs text-muted">· {{ $entry->actor_email ?? __('system') }} · {{ $entry->created_at->diffForHumans() }}</span></li>
            @empty
                <li class="px-4 py-2 text-muted sm:px-6">{{ __('Nothing recorded yet.') }}</li>
            @endforelse
        </ul>
    </x-signal.ui.settings-section>
</x-signal.layouts.admin>
