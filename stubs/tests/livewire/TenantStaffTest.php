<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\TenantStaffManager;
use Livewire\Livewire;
use Tests\TestCase;

class TenantStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_members_can_be_added_to_the_tenant(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $otherUser = User::factory()->create();

        Livewire::test(TenantStaffManager::class, ['tenant' => $tenant])
            ->set('addStaffForm', [
                'email' => $otherUser->email,
                'role' => 'staff',
            ])
            ->call('addStaffMember');

        $this->assertCount(1, $tenant->fresh()->users);
        $this->assertTrue($otherUser->fresh()->belongsToTenant($tenant));
    }

    public function test_staff_member_roles_can_be_updated(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $tenant->users()->attach(
            $otherUser = User::factory()->create(), ['role' => 'staff']
        );

        Livewire::test(TenantStaffManager::class, ['tenant' => $tenant])
            ->set('managingRoleFor', $otherUser)
            ->set('currentRole', 'admin')
            ->call('updateRole');

        $this->assertTrue($otherUser->fresh()->hasTenantRole($tenant->fresh(), 'admin'));
    }

    public function test_staff_members_can_be_removed_from_the_tenant(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $tenant->users()->attach(
            $otherUser = User::factory()->create(), ['role' => 'staff']
        );

        Livewire::test(TenantStaffManager::class, ['tenant' => $tenant])
            ->set('staffIdBeingRemoved', $otherUser->id)
            ->call('removeStaffMember');

        $this->assertCount(0, $tenant->fresh()->users);
    }

    public function test_only_authorized_staff_can_remove_other_staff(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $tenant->users()->attach(
            $otherUser = User::factory()->create(), ['role' => 'staff']
        );

        $tenant->users()->attach(
            $thirdUser = User::factory()->create(), ['role' => 'staff']
        );

        $this->actingAs($otherUser);

        Livewire::test(TenantStaffManager::class, ['tenant' => $tenant])
            ->set('staffIdBeingRemoved', $thirdUser->id)
            ->call('removeStaffMember')
            ->assertStatus(403);
    }
}
