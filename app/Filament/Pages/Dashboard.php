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
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        $user = AuthHelper::user();
        $widgets = [];

        // Account widget for all users
        if ($user) {
            $widgets[] = AccountWidget::class;
        }

        // Filament Info only for developers
        if ($user && $user->isDeveloper()) {
            $widgets[] = FilamentInfoWidget::class;
        }

        return $widgets;
    }

    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'md' => 2,
            'lg' => 3,
            'xl' => 4,
        ];
    }
}
