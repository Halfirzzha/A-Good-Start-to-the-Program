<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AccountWidget;
use App\Filament\Widgets\FilamentInfoWidget;
use App\Support\AuthHelper;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use HasPageShield;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -2;

    /**
     * Get the widgets displayed on the dashboard.
     *
     * Widgets are conditionally rendered based on user permissions
     * following enterprise RBAC best practices.
     *
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        $user = AuthHelper::user();
        if (! $user) {
            return [];
        }

        $widgets = [];

        // Account widget - visible to all authenticated users
        $widgets[] = AccountWidget::class;

        // Filament Info - only for developers (debug/system info)
        if ($user->isDeveloper()) {
            $widgets[] = FilamentInfoWidget::class;
        }

        return $widgets;
    }

    /**
     * Get responsive column layout for widget grid.
     *
     * @return int|array<string, int>
     */
    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'sm' => 1,
            'md' => 2,
            'lg' => 3,
            'xl' => 4,
            '2xl' => 4,
        ];
    }
}
