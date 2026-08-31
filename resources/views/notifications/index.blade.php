<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Notifications')"
        :description="__('Unread deployment and infrastructure failures that need your attention.')"
    >
        @if (auth()->user()->unreadNotifications()->exists())
            <x-slot:buttons>
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="button primary">{{ __('Mark all as read') }}</button>
                </form>
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

    <div class="mt-8 space-y-3">
        @forelse ($notifications as $notification)
            @php($destination = \App\Notifications\FailureNotification::destination($notification->data))
            <article @class([
                'rounded-lg border p-5',
                'border-red-300 bg-red-50' => $notification->read_at === null,
                'border-primary bg-primary' => $notification->read_at !== null,
            ])>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold text-primary">{{ $notification->data['title'] ?? __('Infrastructure failure') }}</h2>
                            @if ($notification->read_at === null)
                                <span class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold uppercase text-white">{{ __('Unread') }}</span>
                            @endif
                        </div>
                        <p class="mt-1 whitespace-pre-wrap break-words text-sm text-secondary">{{ $notification->data['message'] ?? __('The operation failed.') }}</p>
                        <p class="mt-2 text-xs text-secondary">{{ $notification->created_at->diffForHumans() }}</p>
                    </div>
                    @if ($destination)
                        @if ($notification->read_at === null)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <button type="submit" class="button primary">{{ __('View and mark read') }}</button>
                            </form>
                        @else
                            <a href="{{ $destination }}" class="button primary">{{ __('View') }}</a>
                        @endif
                    @endif
                </div>
            </article>
        @empty
            <x-lists.empty
                :title="__('No failure notifications')"
                :description="__('New deployment and infrastructure failures will appear here.')"
            />
        @endforelse
    </div>

    <div class="mt-6">
        {{ $notifications->links() }}
    </div>
</x-layouts.app>
