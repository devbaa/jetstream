<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_can_view_the_portal(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $account = CustomerAccount::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user->fresh())
            ->get('/portal')
            ->assertOk()
            ->assertSee($account->name);
    }

    public function test_users_without_customer_accounts_cannot_view_portal_account_settings(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/portal/account')
            ->assertStatus(403);
    }

    public function test_customers_can_switch_between_accounts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $first = CustomerAccount::factory()->create(['user_id' => $user->id]);
        $second = CustomerAccount::factory()->create(['user_id' => $user->id]);

        $user->fresh()->switchCustomerAccount($first);

        $this->actingAs($user->fresh())
            ->put('/portal/current-account', ['customer_account_id' => $second->id])
            ->assertRedirect(route('portal.show'));

        $this->assertEquals($second->id, $user->fresh()->current_customer_account_id);
    }

    public function test_customers_can_self_register_with_tenants_that_allow_it(): void
    {
        $tenant = Tenant::factory()->allowsCustomerRegistration()->create();

        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->post('/portal/register/'.$tenant->slug)
            ->assertRedirect(route('portal.show'));

        $this->assertTrue($user->fresh()->isCustomerOf($tenant));
    }

    public function test_customers_cannot_self_register_with_tenants_that_disallow_it(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->post('/portal/register/'.$tenant->slug)
            ->assertStatus(404);
    }
}
