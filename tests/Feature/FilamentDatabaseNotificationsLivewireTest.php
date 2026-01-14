<?php

namespace Tests\Feature;

use App\Filament\Livewire\DatabaseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\ComponentRegistry;
use Tests\TestCase;

class FilamentDatabaseNotificationsLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_database_notifications_component_alias_resolves(): void
    {
        $registry = app(ComponentRegistry::class);

        $this->assertSame(
            DatabaseNotifications::class,
            $registry->getClass('filament.livewire.database-notifications')
        );
    }
}
