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

        $invitation = $tenant->customerInvitations()->create(['email' => 'customer@example.com']);

        Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
            ->call('cancelCustomerInvitation', $invitation->id);

        $this->assertCount(0, $tenant->fresh()->customerInvitations);
    }

    public function test_customer_accounts_can_be_deleted(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $tenant = Tenant::factory()->create(['user_id' => $user->id]);

        $account = CustomerAccount::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::test(CustomerAccountManager::class, ['tenant' => $tenant])
            ->set('accountIdBeingDeleted', $account->id)
            ->call('deleteAccount');

        $this->assertNull($account->fresh());
    }
}
