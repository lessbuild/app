{{-- A website's folder, a file's last lines, or log search results, with a breadcrumb back up. Links stay inside the
     browser (the Files tab reloads it in place). --}}
@php($url = fn (array $query = []): string => route('infrastructure.websites.files', [$project, $website->id, ...$query]))
@php($crumbs = $path === '' ? [] : explode('/', $path))
<div class="grid gap-4">
    <form method="GET" action="{{ $url() }}" class="flex flex-wrap items-end gap-3" data-fragment-form>
        <x-signal.ui.input-field name="q" type="search" :label="__('Search the logs (storage/logs)')" :value="$phrase" placeholder="SQLSTATE" maxlength="200" :restore="false" :show-errors="false" />
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Search') }}</x-signal.ui.button>
    </form>

    <nav aria-label="{{ __('Folder') }}" class="flex flex-wrap items-center gap-1 font-mono text-sm">
        <a href="{{ $url() }}" class="text-primary hover:underline">/var/www/{{ $website->deployment_slug }}</a>
        @foreach ($crumbs as $index => $crumb)
            <span class="text-subtle">/</span><a href="{{ $url(['path' => implode('/', array_slice($crumbs, 0, $index + 1))]) }}" class="text-primary hover:underline">{{ $crumb }}</a>
        @endforeach
        @if ($file)<span class="text-subtle">/</span><span class="text-ink">{{ basename($file) }}</span>@endif
    </nav>

    @if ($search !== null)
        @if ($search['error'])<x-signal.ui.alert tone="danger" role="alert">{{ $search['error'] }}</x-signal.ui.alert>@endif
        <p class="text-sm text-muted">{{ trans_choice(':count match for “:phrase”|:count matches for “:phrase”', count($search['matches']), ['count' => count($search['matches']), 'phrase' => $phrase]) }}@if (count($search['matches']) === 200) · {{ __('showing the last 200') }}@endif</p>
        <ol class="grid gap-1 overflow-x-auto rounded-control border border-line bg-surface-muted p-3 font-mono text-xs">
            @foreach ($search['matches'] as $match)
                <li class="whitespace-pre-wrap break-all"><span class="text-muted">{{ $match['file'] }}:{{ $match['line'] }}</span> {{ $match['text'] }}</li>
            @endforeach
        </ol>
    @elseif ($tail !== null)
        @if ($tail['error'])
            <x-signal.ui.alert tone="danger" role="alert">{{ $tail['error'] }}</x-signal.ui.alert>
        @else
            <p class="text-sm text-muted">{{ __('The last :count lines.', ['count' => \App\Queries\Infrastructure\WebsiteFilesQuery::TAIL_LINES]) }} <a href="{{ $url(['file' => $file]) }}" class="ui-link">{{ __('Refresh') }}</a></p>
            <x-signal.ui.code-block :code="$tail['content'] !== '' ? $tail['content'] : __('(empty)')" class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-all text-xs" />
        @endif
    @elseif ($folder !== null)
        @if ($folder['error'])
            <x-signal.ui.alert tone="danger" role="alert">{{ $folder['error'] }}</x-signal.ui.alert>
        @else
            <ul class="divide-y divide-line rounded-control border border-line text-sm">
                @if ($path !== '')
                    <li><a href="{{ $url(['path' => implode('/', array_slice($crumbs, 0, -1))]) }}" class="block px-3 py-2 text-primary hover:bg-surface-muted">..</a></li>
                @endif
                @forelse ($folder['entries'] as $entry)
                    @php($target = ($path !== '' ? $path.'/' : '').$entry['name'])
                    <li>
                        <a href="{{ $entry['type'] === 'file' ? $url(['file' => $target]) : $url(['path' => $target]) }}" class="flex items-center justify-between gap-3 px-3 py-2 hover:bg-surface-muted">
                            <span class="min-w-0 truncate font-mono">{{ $entry['name'] }}{{ $entry['type'] === 'folder' ? '/' : '' }}{{ $entry['type'] === 'link' ? ' →' : '' }}</span>
                            <span class="shrink-0 text-xs text-muted">@if ($entry['type'] === 'file'){{ \Illuminate\Support\Number::fileSize($entry['size'], $entry['size'] < 1024 ? 0 : 1) }} · @endif{{ \Carbon\CarbonImmutable::createFromTimestamp($entry['modified'])->diffForHumans() }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-3 py-2 text-muted">{{ __('This folder is empty.') }}</li>
                @endforelse
            </ul>
        @endif
    @endif
</div>
