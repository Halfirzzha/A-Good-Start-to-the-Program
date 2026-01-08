<?php

namespace App\Providers;

use App\Listeners\RecordAuthActivity;
use App\Listeners\RecordNotificationFailed;
use App\Listeners\RecordNotificationSent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        Login::class => [
            RecordAuthActivity::class,
        ],
        Failed::class => [
            RecordAuthActivity::class,
        ],
        Logout::class => [
            RecordAuthActivity::class,
        ],
        Lockout::class => [
            RecordAuthActivity::class,
        ],
        PasswordReset::class => [
            RecordAuthActivity::class,
        ],
        NotificationSent::class => [
            RecordNotificationSent::class,
        ],
        NotificationFailed::class => [
            RecordNotificationFailed::class,
        ],
    ];
}
