<?php

namespace App\Providers\Filament;

use App\Support\SystemSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
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
            // ─────────────────────────────────────────────────────────────────
            // PANEL IDENTITY & DEFAULTS
            // ─────────────────────────────────────────────────────────────────
            ->default()
            ->id('admin')
            ->path('admin')

            // ─────────────────────────────────────────────────────────────────
            // AUTHENTICATION
            // ─────────────────────────────────────────────────────────────────
            ->login(\App\Filament\Auth\Pages\Login::class)
            ->passwordReset()
            ->profile(\App\Filament\Auth\Pages\EditProfile::class, false)

            // ─────────────────────────────────────────────────────────────────
            // BRANDING (Dynamic from SystemSettings)
            // ─────────────────────────────────────────────────────────────────
            ->brandName(fn (): string => (string) SystemSettings::getValue('project.name', config('app.name', 'System')))
            ->brandLogo(fn (): ?string => SystemSettings::assetUrl('logo'))
            ->darkModeBrandLogo(fn (): ?string => SystemSettings::assetUrl('logo_dark') ?? SystemSettings::assetUrl('logo'))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): ?string => SystemSettings::assetUrl('favicon'))

            // ─────────────────────────────────────────────────────────────────
            // COLORS & THEME
            // ─────────────────────────────────────────────────────────────────
            ->colors([
                'primary' => Color::Amber,
                'gray' => Color::Slate,
                'danger' => Color::Rose,
                'warning' => Color::Orange,
                'success' => Color::Emerald,
                'info' => Color::Sky,
            ])
            ->font('Inter')

            // ─────────────────────────────────────────────────────────────────
            // UI/UX ENTERPRISE FEATURES
            // ─────────────────────────────────────────────────────────────────
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->unsavedChangesAlerts()
            ->databaseTransactions()
            ->breadcrumbs()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchDebounce('300ms')

            // ─────────────────────────────────────────────────────────────────
            // NAVIGATION GROUPS (Organized & Sorted)
            // ─────────────────────────────────────────────────────────────────
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(__('ui.nav.groups.dashboard'))
                    ->icon('heroicon-o-home')
                    ->collapsed(false)
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label(__('ui.nav.groups.monitoring'))
                    ->icon('heroicon-o-presentation-chart-line')
                    ->collapsed(true)
                    ->collapsible(),
                NavigationGroup::make()
                    ->label(__('ui.nav.groups.security'))
                    ->icon('heroicon-o-shield-check')
                    ->collapsed(true)
                    ->collapsible(),
                NavigationGroup::make()
                    ->label(__('ui.nav.groups.maintenance'))
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->collapsed(true)
                    ->collapsible(),
                NavigationGroup::make()
                    ->label(__('ui.nav.groups.system'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(true)
                    ->collapsible(),
            ])

            // ─────────────────────────────────────────────────────────────────
            // NAVIGATION ITEMS
            // ─────────────────────────────────────────────────────────────────
            ->navigationItems([
                NavigationItem::make(__('ui.nav.items.system_settings'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->group(__('ui.nav.groups.system'))
                    ->sort(1)
                    ->visible(fn (): bool => \Illuminate\Support\Facades\Auth::user()?->can('view_system::setting') ?? false)
                    ->url(function (): string {
                        $recordId = \App\Models\SystemSetting::query()->value('id');
                        if (! $recordId) {
                            return \App\Filament\Resources\SystemSettingResource::getUrl('index');
                        }

                        return \App\Filament\Resources\SystemSettingResource::getUrl('edit', [
                            'record' => $recordId,
                        ]);
                    })
                    ->isActiveWhen(fn (): bool => request()?->routeIs('filament.admin.resources.system-settings.*') ?? false),
            ])

            // ─────────────────────────────────────────────────────────────────
            // ASSETS
            // ─────────────────────────────────────────────────────────────────
            ->assets([])

            // ─────────────────────────────────────────────────────────────────
            // PLUGINS
            // ─────────────────────────────────────────────────────────────────
            ->plugins([
                \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make()
                    ->gridColumns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->resourceCheckboxListColumns(['default' => 1, 'sm' => 2]),
            ])

            // ─────────────────────────────────────────────────────────────────
            // AUTO-DISCOVERY
            // ─────────────────────────────────────────────────────────────────
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')

            // ─────────────────────────────────────────────────────────────────
            // NOTIFICATIONS
            // ─────────────────────────────────────────────────────────────────
            ->databaseNotifications(true, \App\Filament\Livewire\DatabaseNotifications::class)
            ->databaseNotificationsPolling('5s')

            // ─────────────────────────────────────────────────────────────────
            // MIDDLEWARE STACK (Enterprise Security)
            // Order matters: Session → Request ID → Auth Session → Locale →
            // Maintenance → Rate Limit → CSRF → Security Layers
            // ─────────────────────────────────────────────────────────────────
            ->middleware([
                // Core Cookie & Session
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,

                // Request Tracking (Early for consistent ID across all logs)
                \App\Http\Middleware\RequestIdMiddleware::class,

                // Session Security
                AuthenticateSession::class,

                // Localization (After session is available)
                \App\Http\Middleware\SetLocale::class,

                // Maintenance Mode (Before heavy processing)
                \App\Http\Middleware\MaintenanceModeMiddleware::class,

                // Error Sharing
                ShareErrorsFromSession::class,

                // Rate Limiting (Protect against abuse)
                'throttle:admin-panel',

                // Security
                VerifyCsrfToken::class,
                SubstituteBindings::class,

                // Filament Core
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])

            // ─────────────────────────────────────────────────────────────────
            // AUTH MIDDLEWARE (Strict Order: Auth → Security Stamp →
            // Account Active → Role Check → Activity Tracking → Audit)
            // ─────────────────────────────────────────────────────────────────
            ->authMiddleware([
                // 1. Authentication (Must be first)
                Authenticate::class,

                // 2. Session Tampering Protection (Critical for security)
                \App\Http\Middleware\EnsureSecurityStampIsValid::class,

                // 3. Account Status Validation (Blocked, Suspended, etc.)
                \App\Http\Middleware\EnsureAccountIsActive::class,

                // 4. Role/Permission Check for Admin Panel Access
                \App\Http\Middleware\EnsureUserHasRole::class,

                // 5. Activity Tracking (Last seen, IP)
                \App\Http\Middleware\UpdateLastSeenMiddleware::class,

                // 6. Comprehensive Audit Logging (Threat detection included)
                \App\Http\Middleware\AuditLogMiddleware::class,
            ]);
    }
}
