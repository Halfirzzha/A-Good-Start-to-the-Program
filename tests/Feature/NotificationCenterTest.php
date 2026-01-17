<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Filament\Resources\UserNotificationResource;
use App\Jobs\SendSecurityAlert;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_alerts_are_deduplicated_by_hash(): void
    {
        Config::set('security.alert_in_app', true);
        Config::set('security.alert_roles', ['admin']);

        $role = Role::create(['name' => 'admin']);
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active->value,
        ]);
        $user->assignRole($role);

        $payload = [
            'event' => 'security_alert_test',
            'title' => 'Security Alert Test',
            'user_id' => $user->id,
            'request_id' => 'req-123',
        ];

        $job = new SendSecurityAlert($payload);
        $job->handle();
        $job->handle();

        $hash = hash('sha256', 'security_alert_test|req-123|'.$user->id);

        $this->assertSame(
            1,
            NotificationMessage::query()->where('metadata->security_hash', $hash)->count()
        );
    }

    public function test_inbox_badge_counts_unread_notifications(): void
    {
        $role = Role::create(['name' => 'admin']);
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active->value,
        ]);
        $user->assignRole($role);

        $messageA = NotificationMessage::query()->create([
            'title' => 'Notice A',
            'message' => 'Message A',
            'category' => 'security',
            'priority' => 'high',
            'status' => 'draft',
            'target_all' => false,
        ]);
        $messageB = NotificationMessage::query()->create([
            'title' => 'Notice B',
            'message' => 'Message B',
            'category' => 'maintenance',
            'priority' => 'normal',
            'status' => 'draft',
            'target_all' => false,
        ]);
        $messageC = NotificationMessage::query()->create([
            'title' => 'Notice C',
            'message' => 'Message C',
            'category' => 'announcement',
            'priority' => 'normal',
            'status' => 'draft',
            'target_all' => false,
        ]);

        UserNotification::query()->create([
            'notification_id' => $messageA->id,
            'user_id' => $user->id,
            'is_read' => false,
            'delivered_at' => now(),
        ]);
        UserNotification::query()->create([
            'notification_id' => $messageB->id,
            'user_id' => $user->id,
            'is_read' => false,
            'delivered_at' => now(),
        ]);
        UserNotification::query()->create([
            'notification_id' => $messageC->id,
            'user_id' => $user->id,
            'is_read' => true,
            'read_at' => now(),
            'delivered_at' => now(),
        ]);

        $this->actingAs($user);

        $this->assertSame('2', UserNotificationResource::getNavigationBadge());
    }
}
