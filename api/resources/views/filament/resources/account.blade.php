{{-- One customer account in the admin panel: members, projects, billing and its latest activity. Opening it is recorded. --}}
@php($userUrl = fn (string $id): string => \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $id]))
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Account :id · created :date', ['id' => $account->id, 'date' => $account->created_at?->toFormattedDateString()]) }}</p>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section :heading="__('Members')">
            <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                @foreach ($account->memberships as $membership)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <x-filament::link :href="$userUrl($membership->user_id)">{{ $membership->user->email }}</x-filament::link>
                        <span class="text-gray-500 dark:text-gray-400">{{ $membership->role->label() }}@if ($membership->service_access !== null) · {{ implode(', ', $membership->service_access) }}@endif</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
        <x-filament::section :heading="__('Projects')">
            <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                @forelse ($account->projects as $project)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="font-medium">{{ $project->name }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $project->enabledServices->pluck('service')->implode(', ') ?: '—' }}</span>
                    </li>
                @empty
                    <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('No projects.') }}</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>

    <x-filament::section :heading="__('Billing')" :description="$billing ? __('Stripe customer :customer · subscription :status', ['customer' => $billing->stripe_customer_id ?? '—', 'status' => $billing->status]) : __('Never subscribed.')">
        <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
            @forelse ($selections as $selection)
                <li class="py-2">{{ $selection->service }} · {{ $selection->kind->value }} {{ $selection->item_key }}@if ($selection->quantity > 1) ×{{ $selection->quantity }}@endif @if ($selection->ends_at)<span class="text-gray-500 dark:text-gray-400">· {{ __('ends :date', ['date' => $selection->ends_at->toFormattedDateString()]) }}</span>@endif</li>
            @empty
                <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('Free tiers everywhere.') }}</li>
            @endforelse
            @foreach ($usage as $record)
                <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('Usage this month: :meter :quantity (:reported reported to Stripe)', ['meter' => $record->meter, 'quantity' => number_format($record->quantity), 'reported' => number_format($record->reported_quantity)]) }}</li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament::section :heading="__('Recent activity')" :description="__('The account’s latest 25 audit entries.')">
        <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
            @forelse ($audit as $entry)
                <li class="py-2">{{ $entry->action->describe($entry->context ?? []) }} <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $entry->actor_email ?? __('system') }} · {{ $entry->created_at->diffForHumans() }}</span></li>
            @empty
                <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('Nothing recorded yet.') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
