<?php

declare(strict_types=1);

use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Http\Livewire\CustomerAccountManager;
use Laravel\Jetstream\Mail\CustomerInvitation;
use Livewire\Livewire;

test('customers can be invited', function () {
    Mail::fake();

    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
        ->set('inviteCustomerForm', ['email' => 'customer@example.com'])
        ->call('inviteCustomer');

    Mail::assertSent(CustomerInvitation::class);

    expect($tenant->fresh()->customerInvitations)->toHaveCount(1);
});

test('customer invitations can be cancelled', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    $invitation = $tenant->customerInvitations()->create(['email' => 'customer@example.com']);

    Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
        ->call('cancelCustomerInvitation', $invitation->id);

    expect($tenant->fresh()->customerInvitations)->toHaveCount(0);
});

test('customer accounts can be deleted', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    $account = CustomerAccount::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
        ->set('accountIdBeingDeleted', $account->id)
        ->call('deleteAccount');

    expect($account->fresh())->toBeNull();
});
