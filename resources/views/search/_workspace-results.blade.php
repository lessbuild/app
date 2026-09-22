@php
    $matchingGroups = collect($groups)->filter(fn (array $group): bool => $group['results']->isNotEmpty());
    $resultCount = $matchingGroups->sum(fn (array $group): int => $group['results']->count());
@endphp

@if ($query === '')
    <p class="px-4 py-3 text-sm text-muted">{{ __('Type a name, URL, IP address, revision, or description to search this workspace.') }}</p>
@elseif ($resultCount === 0)
    <div class="space-y-2 px-4 py-3" role="status">
        <p class="text-sm text-muted">{{ __('No workspace resources match “:query”.', ['query' => $query]) }}</p>
        <a
            href="{{ route('search.index', ['q' => $query]) }}"
            data-palette-item
            role="option"
            class="ui-link"
        >{{ __('Open full search results') }}</a>
    </div>
@else
    <div class="space-y-4 p-2" aria-label="{{ __('Workspace search results') }}">
        <p class="px-2 pt-1 text-xs text-muted" role="status">
            {{ trans_choice(':count result|:count results', $resultCount, ['count' => $resultCount]) }}
        </p>

        @foreach ($matchingGroups as $key => $group)
            <section aria-labelledby="workspace-search-group-{{ $key }}">
                <h3 id="workspace-search-group-{{ $key }}" class="ui-eyebrow px-2 text-[10px]">
                    {{ $group['label'] }}
                </h3>
                <div class="mt-1 space-y-1">
                    @foreach ($group['results'] as $result)
                        <a
                            href="{{ $result['url'] }}"
                            data-palette-item
                            role="option"
                            class="ui-command-item flex items-center justify-between gap-3 rounded-card px-3 py-2.5 text-sm text-ink hover:bg-surface-muted hover:text-ink focus:bg-surface-muted focus:outline-hidden"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{ $result['title'] }}</span>
                                @if ($result['subtitle'])
                                    <span class="mt-0.5 block truncate text-xs text-muted">{{ $result['subtitle'] }}</span>
                                @endif
                            </span>
                            <span aria-hidden="true" class="shrink-0 text-muted">↵</span>
                        </a>
                    @endforeach

                    @if ($group['has_more'])
                        <a
                            href="{{ $group['more_url'] }}"
                            data-palette-item
                            role="option"
                            class="ui-command-item ui-link block rounded-card px-3 py-2 text-xs focus:bg-surface-muted focus:outline-hidden"
                        >{{ __('View more :label', ['label' => strtolower($group['label'])]) }} →</a>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
@endif
