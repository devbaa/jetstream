<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateCustomerAccount;
use App\Actions\Jetstream\CreateTenant;
use App\Models\CustomerAccount;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Http\Livewire\Portal\UpdateAccountNameForm;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\CustomerAccountPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class PortalUpdateAccountNameFormTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(CustomerAccount::class, CustomerAccountPolicy::class);
        Jetstream::useUserModel(User::class);
        Jetstream::createCustomerAccountsUsing(CreateCustomerAccount::class);
    }

    /**
     * Create a tenant with a customer account and return the account's owner and the account.
     *
     * @return array{0: User, 1: CustomerAccount}
     */
    protected function createAccountWithOwner(): array
    {
        $tenantOwner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = (new CreateTenant)->create($tenantOwner, ['name' => 'Acme']);

        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $account = (new CreateCustomerAccount)->create($tenant, $customer, ['name' => 'Jane Co']);

        return [$customer->fresh(), $account];
    }

    public function test_account_owners_can_rename_their_account(): void
    {
        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        Livewire::test(UpdateAccountNameForm::class, ['account' => $account])
            ->set('state.name', 'Jane & Co')
            ->call('updateAccountName')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $this->assertSame('Jane & Co', $account->fresh()->name);
    }

    public function test_account_names_are_required(): void
    {
        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        Livewire::test(UpdateAccountNameForm::class, ['account' => $account])
            ->set('state.name', '')
            ->call('updateAccountName')
            ->assertHasErrors(['name']);

        $this->assertSame('Jane Co', $account->fresh()->name);
    }

    public function test_members_cannot_rename_the_account(): void
    {
        [$owner, $account] = $this->createAccountWithOwner();

        $member = User::forceCreate([
            'name' => 'Mate Member',
            'email' => 'mate@example.com',
            'password' => 'secret',
        ]);

        $account->users()->attach($member);

        $this->actingAs($member);

        Livewire::test(UpdateAccountNameForm::class, ['account' => $account])
            ->set('state.name', 'Hijacked')
            ->call('updateAccountName')
            ->assertStatus(403);

        $this->assertSame('Jane Co', $account->fresh()->name);
    }
}
