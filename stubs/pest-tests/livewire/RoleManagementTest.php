<?php

use App\Models\Tenant;
use App\Models\User;
use Laravel\Jetstream\Http\Livewire\RoleManager;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;

test('tenant owners can create custom roles', function () {
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

    expect($tenant->fresh()->roles)->toHaveCount(1);
    expect(Jetstream::findRole('support-agent', $tenant)->name)->toEqual('Support Agent');
});

test('default roles can be overridden per tenant', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    Livewire::test(RoleManager::class, ['tenant' => $tenant])
        ->call('editRole', 'staff')
        ->set('roleForm.name', 'Custom Staff')
        ->call('saveRole');

    expect(Jetstream::findRole('staff', $tenant)->name)->toEqual('Custom Staff');
});

test('custom roles can be deleted when unassigned', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    $role = $tenant->roles()->create([
        'key' => 'temp-role', 'name' => 'Temp', 'permissions' => ['read'],
    ]);

    Livewire::test(RoleManager::class, ['tenant' => $tenant])
        ->set('roleIdBeingDeleted', $role->id)
        ->call('deleteRole');

    expect($tenant->fresh()->roles)->toHaveCount(0);
});

test('non owners cannot manage roles', function () {
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
});
