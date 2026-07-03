<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\RoleManager;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owners_can_create_custom_roles(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        Livewire::test(RoleManager::class, ['tenant' => $tenant])
            ->set('roleForm', [
                'key' => 'support-agent',
                'name' => 'Support Agent',
                'description' => 'Handles support requests.',
                'permissions' => ['read', 'update'],
            ])
            ->call('saveRole');

        $this->assertCount(1, $tenant->fresh()->roles);
        $this->assertEquals('Support Agent', Jetstream::findRole('support-agent', $tenant)->name);
    }

    public function test_default_roles_can_be_overridden_per_tenant(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        Livewire::test(RoleManager::class, ['tenant' => $tenant])
            ->call('editRole', 'staff')
            ->set('roleForm.name', 'Custom Staff')
            ->call('saveRole');

        $this->assertEquals('Custom Staff', Jetstream::findRole('staff', $tenant)->name);
    }

    public function test_custom_roles_can_be_deleted_when_unassigned(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $role = $tenant->roles()->create([
            'key' => 'temp-role', 'name' => 'Temp', 'permissions' => ['read'],
        ]);

        Livewire::test(RoleManager::class, ['tenant' => $tenant])
            ->set('roleIdBeingDeleted', $role->id)
            ->call('deleteRole');

        $this->assertCount(0, $tenant->fresh()->roles);
    }

    public function test_non_owners_cannot_manage_roles(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $tenant->users()->attach(
            $staff = User::factory()->create(), ['role' => 'staff']
        );

        $this->actingAs($staff);

        Livewire::test(RoleManager::class, ['tenant' => $tenant])
            ->set('roleForm', [
                'key' => 'sneaky',
                'name' => 'Sneaky',
                'description' => '',
                'permissions' => ['read'],
            ])
            ->call('saveRole')
            ->assertStatus(403);
    }
}
