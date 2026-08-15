<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Laravel\Jetstream\Http\Livewire\RoleManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;
use Livewire\Livewire;

test('tenant owners can create custom roles', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    // Livewire component tests do not run through the "tenant.context"
    // middleware, so the tenant context is established explicitly here;
    // tenant scoped queries fail closed without it.
    app(TenantContext::class)->set($tenant);

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

    app(TenantContext::class)->set($tenant);

    Livewire::test(RoleManager::class, ['tenant' => $tenant])
        ->call('editRole', 'staff')
        ->set('roleForm.name', 'Custom Staff')
        ->call('saveRole');

    expect(Jetstream::findRole('staff', $tenant)->name)->toEqual('Custom Staff');
});

test('custom roles can be deleted when unassigned', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    app(TenantContext::class)->set($tenant);

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

    app(TenantContext::class)->set($tenant);

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
