<?php

namespace App\Filament\Widgets;

use App\Support\AuthHelper;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\FilamentInfoWidget as BaseFilamentInfoWidget;

class FilamentInfoWidget extends BaseFilamentInfoWidget
{
    use HasWidgetShield;

    protected static ?int $sort = -2;

    public static function canView(): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        // Only Developer role can see Filament info
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('widget_FilamentInfoWidget');
    }
}
