<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Http\Livewire\CustomerAccountManager;
use Laravel\Jetstream\Mail\CustomerInvitation;
use Laravel\Jetstream\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_can_be_invited(): void
    {
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

        $this->assertCount(1, $tenant->fresh()->customerInvitations);
    }

    public function test_customer_invitations_can_be_cancelled(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        app(TenantContext::class)->set($tenant);

        $invitation = $tenant->customerInvitations()->create(['email' => 'customer@example.com']);

        Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
            ->call('cancelCustomerInvitation', $invitation->id);

        $this->assertCount(0, $tenant->fresh()->customerInvitations);
    }

    public function test_customer_accounts_can_be_deleted(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        app(TenantContext::class)->set($tenant);

        $account = CustomerAccount::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
            ->set('accountIdBeingDeleted', $account->id)
            ->call('deleteAccount');

        // Customer accounts are soft deleted; "jetstream:purge" erases them later.
        $this->assertTrue($account->fresh()->trashed());
    }
}
