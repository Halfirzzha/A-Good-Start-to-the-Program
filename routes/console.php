<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('maintenance:sync')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::call(function (): void {
    Cache::put('system_health:scheduler_last_run', now()->toIso8601String(), now()->addMinutes(5));
})->everyMinute()->name('health-scheduler-heartbeat')->onOneServer();
