<?php

use App\Models\User;
use Laravel\Jetstream\Http\Livewire\CreateTenantForm;
use Livewire\Livewire;

test('tenants can be created', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    Livewire::test(CreateTenantForm::class)
        ->set(['state' => ['name' => 'Test Organization']])
        ->call('createTenant');

    expect($user->fresh()->ownedTenants)->toHaveCount(1);
    expect($user->fresh()->ownedTenants()->latest('id')->first()->name)->toEqual('Test Organization');
    expect($user->fresh()->ownedTenants()->latest('id')->first()->slug)->toEqual('test-organization');
});
