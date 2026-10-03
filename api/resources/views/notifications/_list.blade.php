{{-- Notifications as links that open them (and mark them read); unread ones are highlighted. --}}
<ul class="divide-y divide-line" aria-label="{{ __('Notifications') }}">
    @foreach ($items as $item)
        <li>
            <a href="{{ route('notifications.open', $item->id) }}" @class(['flex items-start gap-3 px-5 py-4 hover:bg-surface-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus', 'bg-primary-soft/40' => ! $item->read])>
                <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-primary' => ! $item->read, 'bg-transparent' => $item->read]) aria-hidden="true"></span>
                <span class="min-w-0 flex-1">
                    <span @class(['block text-sm', 'font-extrabold text-ink' => ! $item->read, 'font-bold text-muted' => $item->read])>{{ $item->title }}@unless ($item->read)<span class="sr-only"> {{ __('(unread)') }}</span>@endunless</span>
                    @if ($item->body !== '')
                        <span class="mt-0.5 block text-sm text-muted">{{ $item->body }}</span>
                    @endif
                </span>
                <time class="shrink-0 text-xs text-muted" datetime="{{ $item->at->toIso8601String() }}">{{ $item->at->diffForHumans() }}</time>
            </a>
        </li>
    @endforeach
</ul>
