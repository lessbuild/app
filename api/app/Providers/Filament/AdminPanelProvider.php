<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\SecurityHeaders;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Auth\Middleware\Authenticate as SignedIn;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The platform admin panel at /admin, built with Filament. People sign in through the app as usual; the panel then
 * needs a platform admin with a second factor who has confirmed their password in the last few minutes. Changes go
 * through the same Actions as before, so each lands in the admin trail.
 */
final class AdminPanelProvider extends PanelProvider
{
    /**
     * Configure the admin panel.
     *
     * @param  Panel  $panel
     * @return Panel
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('app.name').' admin')
            ->homeUrl(fn (): string => route('dashboard'))
            ->colors(['primary' => Color::Slate])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->navigationGroups([
                NavigationGroup::make(__('Customers')),
                NavigationGroup::make(__('Growth')),
                NavigationGroup::make(__('Operations')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SecurityHeaders::class,
            ])
            ->authMiddleware([
                SignedIn::class,
                EnsurePlatformAdmin::class,
                // EnsurePlatformAdmin answers 404 to everyone else, so the panel isn't advertised. (Filament's own
                // Authenticate would answer 403, and Laravel's middleware priority would run it first.)
                RequirePassword::class.':password.confirm,'.config('platform.admin_confirmation_seconds'),
            ]);
    }
}
