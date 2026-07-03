<?php

use App\Models\Tenant;
use App\Models\User;
use Laravel\Jetstream\Http\Livewire\TenantStaffManager;
use Livewire\Livewire;

test('staff members can be added to the tenant', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    $otherUser = User::factory()->create();

    Livewire::test(TenantStaffManager::class, ['tenant' => $tenant])
        ->set('addStaffForm', [
            'email' => $otherUser->email,
            'role' => 'staff',
        ])
        ->call('addStaffMember');

    expect($tenant->fresh()->users)->toHaveCount(1);
    expect($otherUser->fresh()->belongsToTenant($tenant))->toBeTrue();
});

test('staff member roles can be updated', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    $tenant->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'staff']
    );

    Livewire::test(TenantStaffManager::class, ['tenant' => $tenant])
        ->set('managingRoleFor', $otherUser)
        ->set('currentRole', 'admin')
        ->call('updateRole');

    expect($otherUser->fresh()->hasTenantRole($tenant->fresh(), 'admin'))->toBeTrue();
});

test('staff members can be removed from the tenant', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    $tenant->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'staff']
    );

    Livewire::test(TenantStaffManager::class, ['tenant' => $tenant])
        ->set('staffIdBeingRemoved', $otherUser->id)
        ->call('removeStaffMember');

    expect($tenant->fresh()->users)->toHaveCount(0);
});

test('only authorized staff can remove other staff', function () {
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
});
