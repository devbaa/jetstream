<?php

use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Models\User;

test('customers can view the portal', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $account = CustomerAccount::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user->fresh())
        ->get('/portal')
        ->assertOk()
        ->assertSee($account->name);
});

test('users without customer accounts cannot view portal account settings', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->get('/portal/account')
        ->assertStatus(403);
});

test('customers can switch between accounts', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $first = CustomerAccount::factory()->create(['user_id' => $user->id]);
    $second = CustomerAccount::factory()->create(['user_id' => $user->id]);

    $user->fresh()->switchCustomerAccount($first);

    $this->actingAs($user->fresh())
        ->put('/portal/current-account', ['customer_account_id' => $second->id])
        ->assertRedirect(route('portal.show'));

    expect($user->fresh()->current_customer_account_id)->toEqual($second->id);
});

test('customers can self register with tenants that allow it', function () {
    $tenant = Tenant::factory()->allowsCustomerRegistration()->create();

    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->post('/portal/register/'.$tenant->slug)
        ->assertRedirect(route('portal.show'));

    expect($user->fresh()->isCustomerOf($tenant))->toBeTrue();
});

test('customers cannot self register with tenants that disallow it', function () {
    $tenant = Tenant::factory()->create();

    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->post('/portal/register/'.$tenant->slug)
        ->assertStatus(404);
});
