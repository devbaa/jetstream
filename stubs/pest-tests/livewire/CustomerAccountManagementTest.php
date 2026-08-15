<?php

declare(strict_types=1);

use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Http\Livewire\CustomerAccountManager;
use Laravel\Jetstream\Mail\CustomerInvitation;
use Laravel\Jetstream\Tenancy\TenantContext;
use Livewire\Livewire;

test('customers can be invited', function () {
    Mail::fake();

    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    // Livewire component tests do not run through the "tenant.context"
    // middleware, so the tenant context is established explicitly here;
    // tenant scoped queries fail closed without it.
    app(TenantContext::class)->set($tenant);

    Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
        ->set('inviteCustomerForm', ['email' => 'customer@example.com'])
        ->call('inviteCustomer');

    Mail::assertSent(CustomerInvitation::class);

    expect($tenant->fresh()->customerInvitations)->toHaveCount(1);
});

test('customer invitations can be cancelled', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    app(TenantContext::class)->set($tenant);

    $invitation = $tenant->customerInvitations()->create(['email' => 'customer@example.com']);

    Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
        ->call('cancelCustomerInvitation', $invitation->id);

    expect($tenant->fresh()->customerInvitations)->toHaveCount(0);
});

test('customer accounts can be deleted', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $tenant = Tenant::factory()->create(['user_id' => $user->id]);

    app(TenantContext::class)->set($tenant);

    $account = CustomerAccount::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
        ->set('accountIdBeingDeleted', $account->id)
        ->call('deleteAccount');

    // Customer accounts are soft deleted; "jetstream:purge" erases them later.
    expect($account->fresh()->trashed())->toBeTrue();
});
