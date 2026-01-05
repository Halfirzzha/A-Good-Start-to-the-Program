<?php

namespace App\Providers\Filament;

use App\Support\SystemSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Auth\Pages\Login::class)
            ->passwordReset()
            ->brandName(fn (): string => (string) SystemSettings::getValue('project.name', config('app.name', 'System')))
            ->brandLogo(fn (): ?string => SystemSettings::assetUrl('logo'))
            ->favicon(fn (): ?string => SystemSettings::assetUrl('favicon'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->assets([
                Js::make('maintenance-realtime', asset('assets/maintenance/maintenance-realtime.js'))->defer(),
            ])
            ->plugins([
                \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                \App\Http\Middleware\RequestIdMiddleware::class,
                AuthenticateSession::class,
                \App\Http\Middleware\MaintenanceModeMiddleware::class,
                ShareErrorsFromSession::class,
                'throttle:admin-panel',
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\EnsureAccountIsActive::class,
                \App\Http\Middleware\EnsureUserHasRole::class,
            ]);
    }
}
