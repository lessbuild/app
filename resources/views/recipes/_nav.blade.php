{{-- The recipe pages: the account's library, the gallery, reports filed by the person, and reports on the account's recipes. --}}
<x-signal.ui.local-nav :label="__('Recipes')">
    <a href="{{ route('account.recipes') }}" class="ui-local-nav__link" @if (request()->routeIs('account.recipes', 'account.recipes.show')) aria-current="page" @endif>{{ __('Your recipes') }}</a>
    <a href="{{ route('recipes.gallery') }}" class="ui-local-nav__link" @if (request()->routeIs('recipes.gallery', 'recipes.gallery.show')) aria-current="page" @endif>{{ __('Gallery') }}</a>
    <a href="{{ route('recipes.gallery.reports') }}" class="ui-local-nav__link" @if (request()->routeIs('recipes.gallery.reports')) aria-current="page" @endif>{{ __('Your reports') }}</a>
    @if (auth()->user()?->can('update', $account))
        <a href="{{ route('account.recipes.reports') }}" class="ui-local-nav__link" @if (request()->routeIs('account.recipes.reports')) aria-current="page" @endif>{{ __('Reports on our recipes') }}</a>
    @endif
</x-signal.ui.local-nav>
