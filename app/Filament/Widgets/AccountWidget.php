<?php

namespace App\Filament\Widgets;

use App\Support\AuthHelper;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\AccountWidget as BaseAccountWidget;

class AccountWidget extends BaseAccountWidget
{
    use HasWidgetShield;

    protected static ?int $sort = -3;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        // Superadmin and Developer always see this widget
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('widget_AccountWidget');
    }
}
