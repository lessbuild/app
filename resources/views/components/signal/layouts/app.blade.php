@props([
    'title',
    'description' => null,
])

{{-- The signed-in frame: the Signal two-row topbar. ShellComposer supplies $shell from the current user and route. --}}
<x-signal.layouts.base :title="$title" :description="$description">
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>

    <header class="sticky top-0 z-40 border-b border-line bg-surface/90 backdrop-blur" data-mobile-header data-topbar-shell>
        <div class="ui-layout-gutter mx-auto max-w-content">
            {{-- Row one: brand, the platform's areas, account and user menus. --}}
            <div class="flex min-h-16 items-center gap-3">
                @isset($shell)
                    <x-signal.ui.icon-button :label="__('Open navigation')" class="shrink-0 xl:hidden" data-mobile-toggle aria-controls="app-navigation-drawer" aria-expanded="false">
                        <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
                    </x-signal.ui.icon-button>
                @endisset

                <a href="{{ route('dashboard') }}" class="flex min-w-0 shrink-0 items-center gap-2.5 text-sm font-extrabold tracking-tight text-ink" aria-label="{{ __(':app home', ['app' => config('app.name')]) }}">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-ink text-surface shadow-soft" aria-hidden="true">↗</span>
                    <span class="hidden truncate sm:inline">{{ config('app.name') }}</span>
                </a>

                @isset($shell)
                    <nav id="app-primary-navigation" data-desktop-navigation class="ui-horizontal-scroll hidden min-w-0 flex-1 items-center gap-1 overflow-x-auto pl-2 xl:flex" aria-label="{{ __('Platform') }}">
                        @foreach ($shell->primaryNav as $link)
                            <a href="{{ $link->url }}" class="topbar-nav-link" @if ($link->current) aria-current="page" @endif>{{ $link->label }}</a>
                        @endforeach
                    </nav>

                    <div class="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
                        <x-signal.layouts.account-switcher :shell="$shell" class="hidden md:block" />
                        <x-signal.ui.button type="button" size="sm" class="hidden sm:inline-flex" :aria-label="__('Search')" aria-controls="signal-command-palette" aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K" data-signal-command-open>
                            <x-signal.ui.icon name="search" class="h-3.5 w-3.5 stroke-2" />
                            <span class="hidden xl:inline">{{ __('Search') }}</span>
                            <kbd class="ui-kbd hidden xl:inline-flex">⌘K</kbd>
                        </x-signal.ui.button>
                        <x-signal.ui.icon-button :label="__('Search')" class="sm:hidden" aria-controls="signal-command-palette" aria-haspopup="dialog" data-signal-command-open>
                            <x-signal.ui.icon name="search" class="h-[18px] w-[18px] stroke-2" />
                        </x-signal.ui.icon-button>
                        <x-signal.ui.icon-button :label="$shell->unseenChanges > 0 ? __('What’s new (:count new)', ['count' => $shell->unseenChanges]) : __('What’s new')" :href="route('changelog')" class="relative" data-modal-trigger="whats-new" :data-modal-history-url="request()->fullUrlWithQuery(['dialog' => 'whats-new'])">
                            <x-signal.ui.icon name="sparkles" class="h-[18px] w-[18px] stroke-2" />
                            @if ($shell->unseenChanges > 0)
                                <span class="absolute right-1 top-1 size-2 rounded-full bg-primary" aria-hidden="true"></span>
                            @endif
                        </x-signal.ui.icon-button>
                        <x-signal.ui.icon-button :label="$shell->unreadNotifications > 0 ? trans_choice('Notifications, :count unread|Notifications, :count unread', $shell->unreadNotifications, ['count' => $shell->unreadNotifications]) : __('Notifications')" :href="route('notifications.index')" class="relative" :aria-current="request()->routeIs('notifications.*') ? 'page' : null" :data-modal-trigger="request()->routeIs('notifications.*') ? null : 'notifications'" :data-modal-history-url="request()->routeIs('notifications.*') ? null : request()->fullUrlWithQuery(['dialog' => 'notifications'])">
                            <x-signal.ui.icon name="bell" class="h-[18px] w-[18px] stroke-2" />
                            @if ($shell->unreadNotifications > 0)
                                <span class="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-extrabold leading-4 text-white" aria-hidden="true">{{ $shell->unreadNotifications > 9 ? '9+' : $shell->unreadNotifications }}</span>
                            @endif
                        </x-signal.ui.icon-button>
                        <x-signal.ui.icon-button :label="__('Use dark theme')" data-theme-toggle aria-pressed="false">
                            <svg class="h-[19px] w-[19px] dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.2 15.1A8.5 8.5 0 0 1 8.9 3.8 8.6 8.6 0 1 0 20.2 15.1Z" /></svg>
                            <svg class="hidden h-[19px] w-[19px] dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3.6" /><path stroke-linecap="round" d="M12 2.5v2M12 19.5v2M4.3 4.3l1.4 1.4m12.6 12.6 1.4 1.4M2.5 12h2m15 0h2M4.3 19.7l1.4-1.4M18.3 5.7l1.4-1.4" /></svg>
                        </x-signal.ui.icon-button>
                        <details data-signal-menu class="ui-topbar-menu group relative">
                            <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-control px-1.5 text-sm font-bold text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden" aria-label="{{ __('Account menu for :name', ['name' => $shell->user->name]) }}">
                                <x-signal.ui.avatar :name="$shell->user->name" class="ui-avatar-sm text-xs" />
                                <svg class="hidden h-3.5 w-3.5 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90 sm:block" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                            </summary>
                            <div class="absolute right-0 top-full z-40 mt-2 grid min-w-60 gap-1 rounded-panel border border-line bg-surface p-2 shadow-panel">
                                <div class="border-b border-line px-3 pb-3 pt-2">
                                    <p class="truncate text-sm font-extrabold text-ink">{{ $shell->user->name }}</p>
                                    <p class="truncate text-xs text-muted">{{ $shell->user->email }}</p>
                                </div>
                                @if ($shell->accountLinks !== [])
                                    <p class="px-3 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $shell->account?->name }}</p>
                                    @foreach ($shell->accountLinks as $link)
                                        <a href="{{ $link->url }}" class="topbar-nav-link w-full" @if ($link->current) aria-current="page" @endif>{{ $link->label }}</a>
                                    @endforeach
                                @endif
                                <div class="mt-1 grid gap-1 border-t border-line pt-1">
                                    <a href="{{ route('settings.profile') }}" class="topbar-nav-link w-full" @if (request()->routeIs('settings.*')) aria-current="page" @endif>{{ __('Your settings') }}</a>
                                    <a href="{{ route('help') }}" class="topbar-nav-link w-full">{{ __('Help centre') }}</a>
                                    <button type="button" class="topbar-nav-link w-full" data-modal-trigger="feedback-modal">{{ __('Send feedback') }}</button>
                                    @if ($shell->user->is_platform_admin)
                                        <a href="{{ \App\Filament\Pages\Dashboard::getUrl(panel: 'admin') }}" class="topbar-nav-link w-full">{{ __('Platform admin') }}</a>
                                    @endif
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="topbar-nav-link w-full">{{ __('Sign out') }}</button>
                                    </form>
                                </div>
                            </div>
                        </details>
                    </div>
                @endisset
            </div>

            {{-- Row two: project context on the left, the current area's sections on the right. --}}
            @isset($shell)
                @if ($shell->account !== null)
                    <div class="flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-line py-2">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <x-signal.layouts.project-switcher :shell="$shell" />
                        </div>
                        @if ($shell->sectionNav !== [])
                            <nav class="ui-horizontal-scroll flex min-w-0 max-w-full items-center justify-end gap-1 overflow-x-auto" aria-label="{{ $shell->sectionLabel }}">
                                @foreach ($shell->sectionNav as $link)
                                    <a href="{{ $link->url }}" class="topbar-nav-link" @if ($link->current) aria-current="page" @endif>{{ $link->label }}</a>
                                @endforeach
                            </nav>
                        @endif
                    </div>
                @endif
            @endisset
        </div>
    </header>

    <main id="main-content" tabindex="-1" class="ui-layout-gutter mx-auto w-full max-w-content space-y-6 py-7 sm:py-9">
        @if (isset($shell) && $shell->limitWarning !== null)
            @php($near = $shell->limitWarning)
            <x-signal.ui.alert :tone="$near->percent() >= 100 ? 'danger' : 'warning'" role="status">
                {{ $near->percent() >= 100
                    ? __('You’ve reached your plan’s limit of :limit :label:monthly.', ['limit' => number_format((int) $near->limit), 'label' => $near->label, 'monthly' => $near->monthly ? __(' this month') : ''])
                    : __('You’ve used :used of :limit :label on your plan:monthly.', ['used' => number_format($near->used), 'limit' => number_format((int) $near->limit), 'label' => $near->label, 'monthly' => $near->monthly ? __(' this month') : '']) }}
                @php($upgrade = app(\App\Services\Billing\PlanUsage::class)->upgradeFor($near))
                @if ($upgrade)
                    {{ __(':service :tier gives :limit :label for $:price a month.', ['service' => $upgrade['service'], 'tier' => $upgrade['tier']->name, 'limit' => $upgrade['limit'] === null ? __('unlimited') : number_format($upgrade['limit']), 'label' => $near->label, 'price' => number_format($upgrade['monthlyCents'] / 100, $upgrade['monthlyCents'] % 100 === 0 ? 0 : 2)]) }}
                    <a href="{{ route('account.billing').'#billing-'.$near->service }}" class="font-semibold underline">{{ __('Upgrade') }}</a>
                @else
                    <a href="{{ route('account.billing').'#billing-'.$near->service }}" class="font-semibold underline">{{ __('See plans') }}</a>
                @endif
            </x-signal.ui.alert>
        @endif
        @if (session('feedback'))
            <x-signal.ui.alert tone="success" role="status">{{ session('feedback') }}</x-signal.ui.alert>
        @endif
        {{ $slot }}
    </main>

    @isset($shell)
        <x-signal.layouts.command-palette :shell="$shell" />
        @if ($shell->canCreateProject)
            {{-- New project, from the dashboard, the project switcher and search; /projects/create is the fallback. --}}
            <x-signal.overlays.modal id="new-project" :title="__('New project')" :description="__('One project per app or site. It starts with a Production environment; you can add staging and others later.')">
                <form method="POST" action="{{ route('projects.store') }}" class="grid gap-5">
                    @csrf
                    <input type="hidden" name="_modal" value="new-project">
                    <x-signal.ui.input-field id="new-project-name" name="name" :label="__('Project name')" maxlength="100" autocomplete="off" required />
                    <x-signal.ui.textarea-field id="new-project-description" name="description" :label="__('Description')" :description="__('Optional. What this project is, for your teammates.')" maxlength="500" rows="3" />
                    <div class="flex justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Create project') }}</x-signal.ui.button></div>
                </form>
            </x-signal.overlays.modal>
        @endif
        {{-- What's new: the latest changelog entries, with the roadmap a click away. --}}
        @unless (request()->routeIs('notifications.*'))
            <x-signal.overlays.modal id="notifications" :title="__('Notifications')">
                <div data-fragment-src="{{ route('notifications.index') }}" data-fragment-refresh data-fragment-fallback="{{ __('Couldn’t load your notifications. Open the inbox.') }}">
                    <p class="text-sm text-muted" role="status">{{ __('Loading notifications…') }}</p>
                </div>
            </x-signal.overlays.modal>
        @endunless

        <x-signal.overlays.modal id="whats-new" :title="__('What’s new')" :description="__('The latest improvements to :app.', ['app' => config('app.name')])">
            <div class="grid gap-5">
                @foreach (array_slice(\App\Support\Changelog::entries(), 0, 3) as $entry)
                    <section class="grid gap-2">
                        <p class="text-xs font-semibold text-subtle"><time datetime="{{ $entry['date'] }}">{{ \Illuminate\Support\Carbon::parse($entry['date'])->isoFormat('D MMMM YYYY') }}</time></p>
                        <h3 class="font-extrabold text-ink">{{ __($entry['title']) }}</h3>
                        <ul class="grid gap-1.5 text-sm text-muted">
                            @foreach (array_slice($entry['changes'], 0, 6) as $change)
                                <li class="flex gap-2"><x-signal.ui.icon name="check" class="mt-1 size-4 shrink-0 text-success" /><span>{{ __($change) }}</span></li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                    <div class="flex gap-3 text-sm font-bold">
                        <a href="{{ route('changelog') }}" class="text-primary underline">{{ __('Full changelog') }}</a>
                        <a href="{{ route('roadmap') }}" class="text-primary underline">{{ __('What’s coming') }}</a>
                    </div>
                    <form method="POST" action="{{ route('whats-new.seen') }}">@csrf<x-signal.ui.button type="submit" variant="primary" size="sm">{{ __('Got it') }}</x-signal.ui.button></form>
                </div>
            </div>
        </x-signal.overlays.modal>
        {{-- Feedback, from the account menu; reopens with its errors if sending failed. --}}
        <x-signal.overlays.modal id="feedback-modal" :title="__('Send feedback')" :description="__('Tell us what would make :app better, or what’s getting in your way. We read everything.', ['app' => config('app.name')])" :open="$errors->hasAny(['feedback_kind', 'feedback_message'])">
            <form method="POST" action="{{ route('feedback.store') }}" class="grid gap-4">
                @csrf
                <input type="hidden" name="feedback_page" value="{{ url()->current() }}">
                <x-signal.ui.select-field id="feedback-kind" name="feedback_kind" :label="__('What’s it about?')" required>
                    @foreach (\App\Models\Feedback::KINDS as $value => $label)
                        <option value="{{ $value }}" @selected(old('feedback_kind', 'idea') === $value)>{{ __($label) }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.textarea-field id="feedback-message" name="feedback_message" :label="__('Your feedback')" rows="5" maxlength="5000" required />
                <div class="flex justify-end gap-2">
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Send feedback') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.overlays.modal>
        <x-signal.layouts.mobile-sidebar id="app-navigation-drawer" :title="__('Navigation')" :brand-url="route('dashboard')" desktop-navigation="#app-primary-navigation" :breakpoint="1280">
            <div class="grid gap-5">
                <x-signal.layouts.account-switcher :shell="$shell" variant="mobile" />
                <nav class="grid gap-1" aria-label="{{ __('Platform') }}">
                    @foreach ($shell->primaryNav as $link)
                        <a href="{{ $link->url }}" class="app-sidebar-link" @if ($link->current) aria-current="page" @endif>
                            @if ($link->icon)<svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $link->icon }}"></use></svg>@endif
                            <span>{{ $link->label }}</span>
                        </a>
                    @endforeach
                </nav>
                @if ($shell->accountLinks !== [])
                    <nav class="grid gap-1" aria-label="{{ __('Account') }}">
                        <p class="px-3 pb-1 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $shell->account?->name }}</p>
                        @foreach ($shell->accountLinks as $link)
                            <a href="{{ $link->url }}" class="app-sidebar-link" @if ($link->current) aria-current="page" @endif>
                                @if ($link->icon)<svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $link->icon }}"></use></svg>@endif
                                <span>{{ $link->label }}</span>
                            </a>
                        @endforeach
                    </nav>
                @endif
            </div>
        </x-signal.layouts.mobile-sidebar>
    @endisset
</x-signal.layouts.base>
