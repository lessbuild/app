@props(['shell'])

{{-- ⌘K quick navigation (signal-topbar.js). Static entries filter as you type; /search adds projects, domains and members. --}}
@php
    $sections = [];
    $quick = [];
    if ($shell->canCreateProject) {
        $quick[] = ['label' => __('New project'), 'url' => request()->fullUrlWithQuery(['dialog' => 'new-project']), 'keywords' => 'create add'];
    }
    if ($shell->account !== null && $shell->user->can('manageMembers', $shell->account)) {
        $quick[] = ['label' => __('Invite someone'), 'url' => route('account.members', ['dialog' => 'invite-member']), 'keywords' => 'member teammate invitation'];
    }
    if ($shell->account !== null && $shell->user->can('manageApiTokens', $shell->account)) {
        $quick[] = ['label' => __('Create an API token'), 'url' => route('account.api-tokens', ['dialog' => 'create-api-token']), 'keywords' => 'key token api ci'];
    }
    $quick[] = ['label' => __('Notifications'), 'url' => route('notifications.index'), 'keywords' => 'inbox alerts'];
    $quick[] = ['label' => __('Your settings'), 'url' => route('settings.profile'), 'keywords' => 'profile password security passkey two-factor sessions privacy'];
    $sections[__('Quick actions')] = $quick;
    $sections[__('Go to')] = array_map(fn ($link) => ['label' => $link->label, 'url' => $link->url, 'keywords' => 'service area'], $shell->primaryNav);
    if ($shell->sectionNav !== []) {
        $sections[$shell->sectionLabel] = array_map(fn ($link) => ['label' => $link->label, 'url' => $link->url, 'keywords' => ''], $shell->sectionNav);
    }
    if ($shell->accountLinks !== []) {
        $sections[$shell->account?->name ?? __('Account')] = array_map(fn ($link) => ['label' => $link->label, 'url' => $link->url, 'keywords' => 'account'], $shell->accountLinks);
    }
@endphp

<dialog id="signal-command-palette" data-signal-command-palette data-modal-sheet data-signal-command-search-url="{{ route('search') }}" class="ui-dialog ui-command-dialog max-h-[80vh] overflow-hidden" aria-labelledby="signal-command-title" aria-modal="true">
    <div class="p-5 sm:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow">{{ __('Quick navigation') }}</p>
                <h2 id="signal-command-title" class="mt-2 text-xl font-extrabold text-ink">{{ __('Search :account', ['account' => $shell->account?->name ?? config('app.name')]) }}</h2>
            </div>
            <form method="dialog">
                <x-signal.ui.icon-button type="submit" :label="__('Close quick navigation')">
                    <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
                </x-signal.ui.icon-button>
            </form>
        </div>
        <label for="signal-command-query" class="sr-only">{{ __('Search pages, projects, domains and people') }}</label>
        <x-signal.ui.input id="signal-command-query" data-signal-command-input type="search" maxlength="100" class="mt-6 text-base" autocomplete="off" :placeholder="__('Search pages, projects, domains and people…')" />
        <nav data-signal-command-results class="ui-command-list mt-4 max-h-[min(28rem,55vh)] overflow-y-auto" aria-label="{{ __('Results') }}">
            @foreach ($sections as $heading => $items)
                <section class="ui-command-section" data-signal-command-section>
                    <p class="ui-command-heading">{{ $heading }}</p>
                    @foreach ($items as $item)
                        <div class="ui-command-row" data-signal-command-row>
                            <a href="{{ $item['url'] }}" data-signal-command-item data-search="{{ mb_strtolower($item['label'].' '.$item['keywords']) }}" class="ui-command-item">
                                <span class="truncate">{{ $item['label'] }}</span>
                                <span class="ui-command-meta" aria-hidden="true">↵</span>
                            </a>
                        </div>
                    @endforeach
                </section>
            @endforeach
            <div data-signal-command-dynamic-results class="contents" aria-label="{{ __('Search results') }}"></div>
        </nav>
        <p data-signal-command-empty hidden class="px-3 py-6 text-center text-sm text-muted">{{ __('Nothing matches.') }}</p>
        <p data-signal-command-status class="sr-only" role="status" aria-live="polite"></p>
        <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-[10px] font-bold uppercase tracking-wide text-subtle"><span>{{ __('↑↓ to move · ↵ to open') }}</span><kbd class="ui-kbd">Esc</kbd></div>
    </div>
</dialog>
