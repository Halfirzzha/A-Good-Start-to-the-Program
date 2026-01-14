<?php

namespace Tests\Unit;

use App\Notifications\UserInvitationNotification;
use Tests\TestCase;

class UserInvitationNotificationTest extends TestCase
{
    public function test_queueable_properties_are_available(): void
    {
        $notification = new UserInvitationNotification('token');

        $this->assertTrue(property_exists($notification, 'connection'));
        $this->assertTrue(property_exists($notification, 'queue'));
    }
}
