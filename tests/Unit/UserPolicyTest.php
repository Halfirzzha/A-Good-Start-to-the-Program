<?php

namespace Tests\Unit;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_requires_permission(): void
    {
        Permission::create(['name' => 'update_user']);

        $managerRole = Role::create(['name' => 'manager']);
        $adminRole = Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo('update_user');
        Role::create(['name' => 'user']);

        $actor = User::factory()->create(['account_status' => AccountStatus::Active->value]);
        $actor->assignRole($managerRole);

        $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);
        $target->assignRole('user');

        $policy = new UserPolicy();

        $this->assertFalse($policy->update($actor, $target));

        $actor->syncRoles([$adminRole]);

        $this->assertTrue($policy->update($actor, $target));
    }
}
